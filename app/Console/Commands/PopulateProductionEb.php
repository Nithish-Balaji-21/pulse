<?php

namespace App\Console\Commands;

use App\Models\Pulse\Device;
use App\Models\Pulse\PithProduction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * @deprecated This command is no longer in use and may be removed in future versions.
 */
class PopulateProductionEb extends Command
{
    protected $signature = 'populate:eb_units {--dry-run : Preview the updates without saving them}';

    protected $description = '[DEPRECATED] Populate eb units for (old) productions that dont have';

    public function handle()
    {
        $this->warn('⚠️ This command is deprecated and may be removed in future versions.');

        // Cache devices by team_id for performance
        $devices = Device::where('module', 'pith')->get()->keyBy('team_id');

        // Get existing records without eb units
        $pithProductions = PithProduction::whereNull('eb_units')->get();

        foreach ($pithProductions as $pithProduction) {
            try {
                $shiftStart = $pithProduction->start_time;
                $shiftEnd = $pithProduction->end_time;

                // Find the device for the team
                $device = $devices->get($pithProduction->team_id);
                if (! $device) {
                    $this->warn("Device not found for team_id {$pithProduction->team_id}. Skipping record ID {$pithProduction->id}.");

                    continue;
                }

                // Calculate eb_units
                $ebUnits = DB::table('pulse_summary_power_states')
                    ->where('device_id', $device->device_id)
                    ->where(function ($query) use ($shiftStart, $shiftEnd) {
                        $query->whereBetween('start_time', [$shiftStart, $shiftEnd])
                            ->orWhereBetween('end_time', [$shiftStart, $shiftEnd])
                            ->orWhere(function ($query) use ($shiftStart, $shiftEnd) {
                                $query->where('start_time', '<', $shiftStart)
                                    ->where('end_time', '>', $shiftEnd);
                            });
                    })
                    ->get()
                    ->map(fn ($record) => $this->calculateOverlapUnits($record, $shiftStart, $shiftEnd))
                    ->sum();

                // Validate calculated eb_units
                if (! is_numeric($ebUnits)) {
                    $this->warn("Invalid eb_units for record ID {$pithProduction->id}. Skipping update.");

                    continue;
                }

                // Check for dry-run mode
                if ($this->option('dry-run')) {
                    $this->info("Dry Run: Would update eb_units for record ID {$pithProduction->id} with value {$ebUnits}");

                    continue;
                }

                // Update the pith_production record
                $pithProduction->update(['eb_units' => $ebUnits]);

                $this->info("Successfully updated eb_units for record ID {$pithProduction->id} with value {$ebUnits}");
            } catch (\Exception $e) {
                $this->error("Failed to process record ID {$pithProduction->id}: {$e->getMessage()}");
            }
        }

        $this->info('EB Units population process completed.');
    }

    /**
     * Calculate the proportional EB units for overlapping time ranges.
     *
     * @param  object  $record  - The device data state record.
     * @param  string  $shiftStart  - Start time of the shift.
     * @param  string  $shiftEnd  - End time of the shift.
     * @return float - The proportional EB units.
     */
    private function calculateOverlapUnits($record, $shiftStart, $shiftEnd)
    {
        $overlapStart = max(strtotime($record->start_time), strtotime($shiftStart));
        $overlapEnd = min(strtotime($record->end_time), strtotime($shiftEnd));
        $overlapDuration = max(0, $overlapEnd - $overlapStart);
        $totalDuration = strtotime($record->end_time) - strtotime($record->start_time);
        $recordEbUnits = is_numeric($record->eb_units) ? (float) $record->eb_units : 0;

        $proportionalUnits = ($totalDuration > 0)
                            ? ($overlapDuration / $totalDuration) * $recordEbUnits
                            : 0;

        return round($proportionalUnits, 2);
    }
}
