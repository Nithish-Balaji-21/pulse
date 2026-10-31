<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Growbag factories were created without a RS_PER_EB_UNIT setting — the wizard collected the rate
 * but only wrote the row for pith and fibre teams (fixed in CreateTeamForm). Without it
 * ProcessDeviceDataForGrowBagsJob falls back to 0, so every growbag summary row records an EB cost
 * of zero however many units the device counted.
 *
 * The rate is a per-factory tariff nobody can guess from here, so this seeds the same default the
 * wizard now uses and leaves it editable under Settings — where the growbags module is now listed
 * alongside pith and fibre.
 *
 * Existing summary rows keep their zero EB cost: correcting those means re-running the nightly job
 * over the affected dates, which is a deliberate operation rather than a migration side effect.
 */
return new class extends Migration
{
    /**
     * The wizard's growbags default, matching DemoScaffoldSeeder.
     */
    private const DEFAULT_RATE = '9.50';

    public function up(): void
    {
        $rows = $this->growbagTeamsMissingTheSetting()
            ->map(fn (int $teamId) => [
                'team_id' => $teamId,
                'module' => 'growbags',
                'key' => 'RS_PER_EB_UNIT',
                'text' => 'Rupees per EB unit',
                'value' => self::DEFAULT_RATE,
                'options' => '',
                'value_remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            DB::table('pulse_settings')->insert($rows);
        }
    }

    public function down(): void
    {
        // Only the untouched defaults are removed — a factory that has since entered its real
        // tariff keeps it, since re-running this migration would restore the row anyway.
        DB::table('pulse_settings')
            ->where('module', 'growbags')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('value', self::DEFAULT_RATE)
            ->delete();
    }

    /**
     * Teams with growbags active that have no RS_PER_EB_UNIT row at all. The key is matched
     * across every module, not just growbags: a factory running pith or fibre as well already
     * carries the rate under that module, and the jobs read it without filtering on module.
     *
     * @return Collection<int, int>
     */
    private function growbagTeamsMissingTheSetting(): Collection
    {
        return DB::table('team_pulse')
            ->whereRaw("JSON_EXTRACT(modules, '$.pulse.modules.growbags.is_active') = TRUE")
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('pulse_settings')
                ->whereColumn('pulse_settings.team_id', 'team_pulse.team_id')
                ->where('pulse_settings.key', 'RS_PER_EB_UNIT'))
            ->pluck('team_id')
            ->map(fn ($id) => (int) $id);
    }
};
