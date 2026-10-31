<?php

use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * `notifications` is a delivery log with no age-based retention — rows only ever
 * disappeared when a team was deleted. The in-app inbox reads the last 7 days
 * (NotificationsController::index), so a one-month purge is invisible to users.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Retention Factory '.uniqid());
    $this->userId = DB::table('users')->insertGetId([
        'name' => 'Retention User',
        'email' => 'retention-'.uniqid().'@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function insertNotification(int $teamId, int $userId, string $createdAt, string $subject): int
{
    return (int) DB::table('notifications')->insertGetId([
        'team_id' => $teamId,
        'user_id' => $userId,
        'date' => Carbon::parse($createdAt)->toDateString(),
        'type' => 'daily',
        'subject' => $subject,
        'body' => 'body',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('deletes notifications older than a month and keeps newer ones', function () {
    $stale = insertNotification($this->teamId, $this->userId, Carbon::now()->subMonths(3)->toDateTimeString(), 'stale');
    $justInside = insertNotification($this->teamId, $this->userId, Carbon::now()->subDays(20)->toDateTimeString(), 'recent');

    $this->artisan('data:cleanup')->assertSuccessful();

    expect(DB::table('notifications')->where('id', $stale)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('id', $justInside)->exists())->toBeTrue();
});

it('keeps notifications inside the inbox window untouched', function () {
    $today = insertNotification($this->teamId, $this->userId, Carbon::now()->toDateTimeString(), 'today');

    $this->artisan('data:cleanup')->assertSuccessful();

    expect(DB::table('notifications')->where('id', $today)->exists())->toBeTrue();
});

it('purges across the batch boundary', function () {
    // DELETE_BATCH_SIZE is 1000; the loop must keep going past a full batch.
    $rows = [];
    for ($i = 0; $i < 1050; $i++) {
        $rows[] = [
            'team_id' => $this->teamId,
            'user_id' => $this->userId,
            'date' => Carbon::now()->subMonths(2)->toDateString(),
            'type' => 'daily',
            'subject' => 'bulk '.$i,
            'body' => 'body',
            'created_at' => Carbon::now()->subMonths(2)->toDateTimeString(),
            'updated_at' => Carbon::now()->subMonths(2)->toDateTimeString(),
        ];
    }
    DB::table('notifications')->insert($rows);

    $this->artisan('data:cleanup')->assertSuccessful();

    expect(DB::table('notifications')->where('team_id', $this->teamId)->count())->toBe(0);
});
