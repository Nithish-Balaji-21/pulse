<?php

use App\Support\Pulse\ProductionEntryPolicyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed production entry policy settings and normalize any existing rows.
     * Consolidates former reseed + simplify migrations for fresh installs.
     */
    public function up(): void
    {
        ProductionEntryPolicyKeys::seedAllPithTeams(now());
        ProductionEntryPolicyKeys::normalizeExistingSettings();
    }

    public function down(): void
    {
        DB::table('pulse_settings')
            ->whereIn('key', ProductionEntryPolicyKeys::all())
            ->delete();
    }
};
