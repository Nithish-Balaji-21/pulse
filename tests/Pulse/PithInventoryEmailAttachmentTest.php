<?php

use App\Mail\ProductionSummaryMail;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\User;
use App\Services\Pulse\PithInventoryExportService;
use App\Services\Pulse\ProductionSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Pulse\Support\TeamFactory;

/**
 * The daily, weekly and monthly pith summary emails attach an inventory PDF
 * covering that same period — the same report as the manual Filter & Export
 * -> PDF button — only when the team has configured pith locations and the
 * period's closing stock clears 100 cft. Yearly/custom-range summaries don't
 * get one. See ProductionSummaryService::buildPithInventoryAttachment().
 */
beforeEach(function () {
    config()->set('app.env', 'production');
    Mail::fake();

    $this->teamId = TeamFactory::create('Pith Stock Report Factory '.uniqid());

    $owner = User::factory()->create(['is_superadmin' => 0, 'is_active' => 1]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $owner->id,
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->yesterday = now()->subDay()->toDateString();
});

it('attaches a PDF when pith locations exist and closing stock clears 100 cft', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => $this->yesterday,
        'cft_offloaded' => 500,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);

    (new ProductionSummaryService($this->teamId))->buildSummaryAndNotify([], 'Yesterday (Wed)', $this->yesterday);

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) {
        return $mail->attachment !== null
            && $mail->attachment['mime'] === 'application/pdf'
            && str_starts_with($mail->attachment['filename'], 'pith-inventory-');
    });
});

/**
 * The mailable is queued, and Laravel JSON encodes the queue payload, so raw
 * binary PDF bytes fail the encode ("Error (5): Malformed UTF-8") and the
 * summary is dropped. Mail::fake() skips serialization, so assert it directly.
 */
it('queues an attachment payload that survives JSON encoding', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => $this->yesterday,
        'cft_offloaded' => 500,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);

    (new ProductionSummaryService($this->teamId))->buildSummaryAndNotify([], 'Yesterday (Wed)', $this->yesterday);

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) {
        expect(json_encode(['command' => serialize(clone $mail)]))->not->toBeFalse()
            ->and(json_last_error())->toBe(JSON_ERROR_NONE);

        // Still a real PDF once decoded, not just encode-safe.
        expect(base64_decode($mail->attachment['content_base64'], true))->toStartWith('%PDF');

        return true;
    });
});

/**
 * A queued payload outlives the deploy that wrote it, so build() has to read
 * both the current base64 shape and the older raw-bytes one — during a rollout
 * a worker can pick up a mailable enqueued by a different release.
 */
it('renders an attachment from either payload shape', function (array $attachment) {
    $mail = new ProductionSummaryMail('Yesterday (Wed)', [], $this->teamId, $attachment);
    $mail->build();

    $attached = collect($mail->rawAttachments)->firstWhere('name', 'report.pdf');

    expect($attached)->not->toBeNull()
        ->and($attached['data'])->toBe('%PDF-1.4 body');
})->with([
    'base64 (current)' => [['content_base64' => base64_encode('%PDF-1.4 body'), 'filename' => 'report.pdf', 'mime' => 'application/pdf']],
    'raw bytes (previous release)' => [['content' => '%PDF-1.4 body', 'filename' => 'report.pdf', 'mime' => 'application/pdf']],
]);

it('still sends the summary when the attachment payload is unusable', function () {
    $mail = new ProductionSummaryMail('Yesterday (Wed)', ['Pith: 500 cft'], $this->teamId, [
        'filename' => 'report.pdf',
        'mime' => 'application/pdf',
        // No content under any key — e.g. a shape this release doesn't know.
    ]);

    $mail->build();

    expect($mail->rawAttachments)->toBeEmpty()
        ->and($mail->subject)->toBe('Yesterday (Wed)');
});

it('sends the summary without a PDF when the attachment fails to render', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => $this->yesterday,
        'cft_offloaded' => 500,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);

    // Stand in for the production failure: dompdf missing from the deployed vendor tree.
    app()->bind(PithInventoryExportService::class, function () {
        throw new RuntimeException('PDF renderer unavailable');
    });

    (new ProductionSummaryService($this->teamId))->buildSummaryAndNotify([], 'Yesterday (Wed)', $this->yesterday);

    Mail::assertQueued(ProductionSummaryMail::class, fn ($mail) => $mail->attachment === null);
});

it('sends no attachment when the team has no pith locations', function () {
    (new ProductionSummaryService($this->teamId))->buildSummaryAndNotify([], 'Yesterday (Wed)', $this->yesterday);

    Mail::assertQueued(ProductionSummaryMail::class, fn ($mail) => $mail->attachment === null);
});

it('sends no attachment when closing stock is 100 cft or less', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => $this->yesterday,
        'cft_offloaded' => 100,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);

    (new ProductionSummaryService($this->teamId))->buildSummaryAndNotify([], 'Yesterday (Wed)', $this->yesterday);

    Mail::assertQueued(ProductionSummaryMail::class, fn ($mail) => $mail->attachment === null);
});

it('attaches a ranged PDF to the weekly summary using the period closing stock', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    $start = now()->subWeek()->startOfWeek();
    $end = now()->subWeek()->endOfWeek();

    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => $start->toDateString(),
        'cft_offloaded' => 500,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);

    (new ProductionSummaryService($this->teamId))
        ->buildSummaryAndNotify([], 'Week (...)', $start, $end, 'pith', 'weekly');

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($start, $end) {
        return $mail->attachment !== null
            && $mail->attachment['filename'] === "pith-inventory-{$start->toDateString()}-to-{$end->toDateString()}.pdf";
    });
});

it('sends no attachment for yearly summaries even with stock above the threshold', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    $start = now()->subYear()->startOfYear();
    $end = now()->subYear()->endOfYear();

    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => $start->toDateString(),
        'cft_offloaded' => 500,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);

    (new ProductionSummaryService($this->teamId))
        ->buildSummaryAndNotify([], 'Year (...)', $start, $end, 'pith', 'yearly');

    Mail::assertQueued(ProductionSummaryMail::class, fn ($mail) => $mail->attachment === null);
});
