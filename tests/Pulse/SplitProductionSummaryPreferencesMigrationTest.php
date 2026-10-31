<?php

use App\Models\User;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The data migration that splits the single PRODUCTION_SUMMARY preference into
 * the four periods, and clears rows that were never anything but the old seed
 * default so the new role defaults can take over.
 *
 * The migration is re-run here against a synthetic copy of the old data shape,
 * inside the suite's rolled-back transaction.
 */
beforeEach(function () {
    $this->migration = require database_path('migrations/2026_07_31_112522_split_production_summary_notification_preferences.php');

    $this->teamId = TeamFactory::create('Migration Factory '.uniqid());

    $this->seedOldStylePreferences = function (string $role, bool $enabled, array $attributes = []): User {
        $user = User::factory()->create(array_merge(['is_superadmin' => 0], $attributes));

        DB::table('team_user')->insert([
            'team_id' => $this->teamId,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pulse_user_notification_preferences')->insert([
            'user_id' => $user->id,
            'team_id' => $this->teamId,
            'notification_type' => NotificationType::LEGACY_PRODUCTION_SUMMARY,
            'is_email_enabled' => $enabled,
            'is_push_enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    };

    $this->rowsFor = fn (User $user) => DB::table('pulse_user_notification_preferences')
        ->where('user_id', $user->id)
        ->where('team_id', $this->teamId)
        ->pluck('is_email_enabled', 'notification_type');
});

it('clears seeded rows so role defaults apply', function () {
    // The old listener seeded admins on and everyone else off.
    $admin = ($this->seedOldStylePreferences)('admin', true);
    $supervisor = ($this->seedOldStylePreferences)('supervisor', false);

    $this->migration->up();

    expect(($this->rowsFor)($admin))->toBeEmpty()
        ->and(($this->rowsFor)($supervisor))->toBeEmpty();
});

it('keeps a hand-edited opt-out and spreads it across the four periods', function () {
    // An admin seeded on but later switched off by hand: not the old default.
    $admin = ($this->seedOldStylePreferences)('admin', false);

    $this->migration->up();

    $rows = ($this->rowsFor)($admin);

    expect($rows)->toHaveCount(4)
        ->and($rows->keys()->all())->toEqualCanonicalizing([
            NotificationType::PRODUCTION_SUMMARY_DAILY,
            NotificationType::PRODUCTION_SUMMARY_WEEKLY,
            NotificationType::PRODUCTION_SUMMARY_MONTHLY,
            NotificationType::PRODUCTION_SUMMARY_YEARLY,
        ])
        ->and($rows->every(fn ($enabled) => (int) $enabled === 0))->toBeTrue();
});

it('leaves no legacy PRODUCTION_SUMMARY rows behind', function () {
    ($this->seedOldStylePreferences)('owner', true);

    $this->migration->up();

    expect(
        DB::table('pulse_user_notification_preferences')
            ->where('team_id', $this->teamId)
            ->where('notification_type', NotificationType::LEGACY_PRODUCTION_SUMMARY)
            ->exists()
    )->toBeFalse();
});

it('clears every seeded row belonging to a superadmin so they receive all factories', function () {
    // Superadmins were seeded off unless they happened to hold the admin role.
    $superadmin = ($this->seedOldStylePreferences)('staff', false, ['is_superadmin' => 1]);

    $this->migration->up();

    expect(($this->rowsFor)($superadmin))->toBeEmpty();
});
