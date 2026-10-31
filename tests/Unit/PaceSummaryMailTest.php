<?php

namespace Tests\Unit;

use App\Jobs\Middleware\PaceSummaryMail;
use App\Mail\ProductionSummaryMail;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;

class PaceSummaryMailTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository([
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'mail' => ['default' => 'smtp', 'mailers' => ['smtp' => ['host' => 'test', 'username' => 'test']]],
            'mail_delivery' => ['store' => 'array', 'interval_seconds' => 60, 'blocked_seconds' => 3600],
        ]));
        $app->instance('cache', new CacheManager($app));
        $app->instance('log', new NullLogger);
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousContainer);
        parent::tearDown();
    }

    private function job(): object
    {
        return new class
        {
            public object $mailable;

            public ?int $delay = null;

            public function __construct()
            {
                $this->mailable = new ProductionSummaryMail('Summary', ['Production'], 1);
            }

            public function release(int $delay): void
            {
                $this->delay = $delay;
            }
        };
    }

    public function test_separate_jobs_share_pacing_and_deferred_mail_can_send(): void
    {
        $middleware = new PaceSummaryMail;
        $sent = 0;
        $send = function () use (&$sent) {
            $sent++;
        };
        $middleware->handle($this->job(), $send);
        $second = $this->job();
        $middleware->handle($second, $send);
        self::assertSame(1, $sent);
        self::assertSame(60, $second->delay);
        Carbon::setTestNow(now()->addSeconds(61));
        $middleware->handle($second, $send);
        self::assertSame(2, $sent);
    }

    public function test_zoho_block_defers_all_summary_jobs_until_cooldown(): void
    {
        $middleware = new PaceSummaryMail;
        $job = $this->job();
        $middleware->handle($job, function () {
            throw new TransportException('Expected 250, got 550 5.4.6 Unusual sending activity detected');
        });
        self::assertSame(3600, $job->delay);
        Carbon::setTestNow(now()->addSeconds(61));
        $next = $this->job();
        $middleware->handle($next, fn () => self::fail('Must not send while blocked'));
        self::assertSame(3539, $next->delay);
        Carbon::setTestNow(now()->addSeconds(3540));
        $sent = false;
        $middleware->handle($next, function () use (&$sent) {
            $sent = true;
        });
        self::assertTrue($sent);
    }

    public function test_other_smtp_errors_remain_visible_and_release_lock(): void
    {
        $middleware = new PaceSummaryMail;
        try {
            $middleware->handle($this->job(), fn () => throw new TransportException('535 Authentication failed'));
            self::fail('SMTP error must propagate');
        } catch (TransportException $exception) {
            self::assertSame('535 Authentication failed', $exception->getMessage());
        }
        Carbon::setTestNow(now()->addSeconds(61));
        $sent = false;
        $middleware->handle($this->job(), function () use (&$sent) {
            $sent = true;
        });
        self::assertTrue($sent);
    }

    public function test_deadline_survives_serialization_and_retries_have_backoff(): void
    {
        $mail = new ProductionSummaryMail('Summary', [], 1);
        $deadline = $mail->retryUntil->timestamp;
        Carbon::setTestNow(now()->addHours(2));
        $restored = unserialize(serialize($mail));
        self::assertSame($deadline, $restored->retryUntil->timestamp);
        self::assertSame([300, 900, 3600], $restored->backoff());
        self::assertSame(0, $restored->tries);
        self::assertSame(3, $restored->maxExceptions);
    }
}
