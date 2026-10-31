<?php

use App\Models\Team;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sole Pulse role-matrix migration (fresh installs):
 * 1) Create pulse_team_roles
 * 2) Seed defaults for Pulse teams (current config: Admin factory-setup off, etc.)
 * 3) Sanitize permission rows (caps / legacy remaps) + strip admin notification prefs
 * 4) Widen cache.value to LONGTEXT when using the database cache store
 *
 * Incremental role backfills (flatten, reader attendance, strip admin factory setup)
 * were removed — a refreshed DB only needs this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_team_roles')) {
            Schema::create('pulse_team_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('team_id');
                $table->string('key', 50);
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->json('permissions');
                $table->timestamps();

                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
                $table->unique(['team_id', 'key']);
                $table->unique(['team_id', 'name']);
            });
        }

        if (Schema::hasTable('pulse_team_roles')) {
            Team::query()
                ->whereHas('pulse')
                ->pluck('id')
                ->each(function (int $teamId): void {
                    $hasRoles = DB::table('pulse_team_roles')
                        ->where('team_id', $teamId)
                        ->exists();

                    if (! $hasRoles) {
                        TeamRolePermissions::seedDefaultsForTeam($teamId);
                    }
                });

            $touchedTeams = [];

            DB::table('pulse_team_roles')
                ->select(['id', 'team_id', 'key', 'permissions'])
                ->orderBy('id')
                ->chunkById(100, function ($rows) use (&$touchedTeams) {
                    foreach ($rows as $role) {
                        $permissions = is_string($role->permissions)
                            ? json_decode($role->permissions, true)
                            : $role->permissions;
                        $permissions = is_array($permissions) ? $permissions : [];

                        $sanitized = TeamRolePermissions::sanitizePermissions(
                            (int) $role->team_id,
                            $permissions,
                            (string) $role->key
                        );

                        // Admin notification preferences default off (Owner may grant later).
                        if ($role->key === 'admin') {
                            $sanitized = array_values(array_filter(
                                $sanitized,
                                fn ($capability) => ! str_starts_with((string) $capability, 'notification_preferences:')
                            ));
                        }

                        DB::table('pulse_team_roles')
                            ->where('id', $role->id)
                            ->update([
                                'permissions' => json_encode($sanitized, JSON_UNESCAPED_UNICODE),
                                'updated_at' => now(),
                            ]);

                        $touchedTeams[(int) $role->team_id] = true;
                    }
                });

            foreach (array_keys($touchedTeams) as $teamId) {
                TeamRolePermissions::forgetCache($teamId);
            }
        }

        if (Schema::hasTable('cache')) {
            $column = collect(DB::select('SHOW COLUMNS FROM `cache` WHERE Field = ?', ['value']))->first();
            $type = strtolower((string) ($column->Type ?? ''));

            if ($type !== '' && ! str_contains($type, 'longtext') && ! str_contains($type, 'mediumtext')) {
                DB::statement('ALTER TABLE `cache` MODIFY `value` LONGTEXT NOT NULL');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_team_roles');
        // Keep cache.value as LONGTEXT — shrinking risks truncating rows.
    }
};
