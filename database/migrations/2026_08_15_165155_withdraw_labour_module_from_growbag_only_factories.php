<?php

use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Labour is withdrawn from growbag-only factories, for the same reason it was withdrawn from
 * fibre-only ones: a production line whose output the device already counts has no per-head
 * attendance to record against it.
 *
 * The rule itself lives in code (Team::getIsDeviceOnlyFactoryAttribute, User::allows, and
 * `not.device_only` on the attendance module in config/pulse_role_permissions.php) so a growbag
 * factory created later is covered too. This migration only settles the state left behind: the
 * stored flag still reads true, roles still carry attendance capabilities, and the recorded
 * attendance rows are now unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamIds = $this->growbagOnlyTeamIds();

        if ($teamIds === []) {
            return;
        }

        // The rows can no longer be reached or re-entered through the UI, so they are removed
        // rather than left orphaned. Staff records stay — they are referenced elsewhere.
        DB::table('pulse_attendance')->whereIn('team_id', $teamIds)->delete();

        foreach ($teamIds as $teamId) {
            $this->setAttendanceActive($teamId, false);
        }
    }

    public function down(): void
    {
        // Deleted attendance rows are not restored — take them from a backup if they are needed.
        foreach ($this->growbagOnlyTeamIds() as $teamId) {
            $this->setAttendanceActive($teamId, true);
        }
    }

    private function setAttendanceActive(int $teamId, bool $isActive): void
    {
        $modules = json_decode(DB::table('team_pulse')->where('team_id', $teamId)->value('modules') ?? '', true);

        if (is_array($modules)) {
            data_set($modules, 'pulse.modules.attendance.is_active', $isActive);

            DB::table('team_pulse')->where('team_id', $teamId)->update([
                'modules' => json_encode($modules),
                'updated_at' => now(),
            ]);
        }

        // Re-sanitize every role against the new module map so the attendance capabilities drop
        // out of the stored permissions rather than lingering as dead entries.
        TeamRolePermissions::syncOnModuleChange($teamId);
    }

    /**
     * Growbags active, pith not — the half of Team::getIsDeviceOnlyFactoryAttribute the earlier
     * fibre-only migration did not already cover. Read straight from the JSON so the migration
     * does not depend on the model's accessors.
     *
     * @return list<int>
     */
    private function growbagOnlyTeamIds(): array
    {
        return DB::table('team_pulse')
            ->whereRaw("JSON_EXTRACT(modules, '$.pulse.modules.growbags.is_active') = TRUE")
            ->whereRaw("COALESCE(JSON_EXTRACT(modules, '$.pulse.modules.pith.is_active'), CAST('false' AS JSON)) = FALSE")
            ->pluck('team_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
};
