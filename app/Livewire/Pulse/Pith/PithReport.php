<?php

namespace App\Livewire\Pulse\Pith;

use App\Models\Pulse\DeviceDataState;
use App\Models\Pulse\PithProduction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class PithReport extends Component
{
    use WithPagination;

    const TOP_X_RECORDS = 6;

    public $pithProduction;

    public $ebUnits;

    public $ebPrice;

    public $ebAmount;

    public $teamId;

    public function mount($id)
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-pith-production'), 403);

        $this->pithProduction = PithProduction::findOrFail($id);
    }

    public function render()
    {
        $reportData = [];

        // Shift start and end time for easy reference
        $shiftStart = $this->pithProduction->start_time;
        $shiftEnd = $this->pithProduction->end_time;
        $this->teamId = $this->pithProduction->team_id;
        $this->ebUnits = $this->pithProduction->eb_units;
        $this->ebAmount = $this->pithProduction->eb_price;

        // Summarize the duration within the shift period
        $reportData = DeviceDataState::select('state',
            DB::raw('SUM(
                    CASE
                        WHEN start_time < "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                            THEN UNIX_TIMESTAMP("'.$shiftEnd.'") - UNIX_TIMESTAMP("'.$shiftStart.'")
                        WHEN start_time < "'.$shiftStart.'" AND end_time <= "'.$shiftEnd.'"
                            THEN UNIX_TIMESTAMP(end_time) - UNIX_TIMESTAMP("'.$shiftStart.'")
                        WHEN start_time >= "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                            THEN UNIX_TIMESTAMP("'.$shiftEnd.'") - UNIX_TIMESTAMP(start_time)
                        ELSE duration
                    END
                ) as total_duration'),
            DB::raw('COUNT(*) as state_count')
        )
            ->where('device_id', $this->pithProduction->device_id)
            ->where(function ($query) use ($shiftStart, $shiftEnd) {
                $query->whereBetween('start_time', [$shiftStart, $shiftEnd])
                    ->orWhereBetween('end_time', [$shiftStart, $shiftEnd])
                    ->orWhere(function ($q) use ($shiftStart, $shiftEnd) {
                        $q->where('start_time', '<=', $shiftStart)
                            ->where('end_time', '>=', $shiftEnd);
                    });
            })
            ->groupBy('state')
            ->orderByRaw("
                CASE state
                    WHEN 'efficient' THEN 1
                    WHEN 'idle' THEN 2
                    WHEN 'overload' THEN 3
                    WHEN 'off' THEN 4
                    WHEN 'shutdown' THEN 5
                    WHEN 'missing' THEN 6
                    ELSE 7 -- For any unspecified states
                END
            ")
            ->get()
            ->keyBy('state')
            ->toArray();

        // Define the states for which we need the top records
        $states = ['efficient', 'off', 'shutdown', 'overload', 'idle', 'missing'];

        // Loop through each state and get the top records
        foreach ($states as $state) {
            $reportData['top_'.$state] = DeviceDataState::select(
                DB::raw('
                            CASE
                                WHEN start_time < "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                                    THEN "'.$shiftStart.'"
                                WHEN start_time < "'.$shiftStart.'" AND end_time <= "'.$shiftEnd.'"
                                    THEN end_time
                                WHEN start_time >= "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                                    THEN start_time
                                ELSE start_time
                            END as adjusted_start_time,
                            CASE
                                WHEN start_time < "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                                    THEN "'.$shiftEnd.'"
                                WHEN start_time < "'.$shiftStart.'" AND end_time <= "'.$shiftEnd.'"
                                    THEN end_time
                                WHEN start_time >= "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                                    THEN "'.$shiftEnd.'"
                                ELSE end_time
                            END as adjusted_end_time,
                            (
                                CASE
                                    WHEN start_time < "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                                        THEN UNIX_TIMESTAMP("'.$shiftEnd.'") - UNIX_TIMESTAMP("'.$shiftStart.'")
                                    WHEN start_time < "'.$shiftStart.'" AND end_time <= "'.$shiftEnd.'"
                                        THEN UNIX_TIMESTAMP(end_time) - UNIX_TIMESTAMP("'.$shiftStart.'")
                                    WHEN start_time >= "'.$shiftStart.'" AND end_time > "'.$shiftEnd.'"
                                        THEN UNIX_TIMESTAMP("'.$shiftEnd.'") - UNIX_TIMESTAMP(start_time)
                                    ELSE duration
                                END
                            ) as adjusted_duration
                        ')
            )
                ->where('device_id', $this->pithProduction->device_id)
                ->where('state', $state)
                ->where(function ($query) use ($shiftStart, $shiftEnd) {
                    $query->whereBetween('start_time', [$shiftStart, $shiftEnd])
                        ->orWhereBetween('end_time', [$shiftStart, $shiftEnd])
                        ->orWhere(function ($q) use ($shiftStart, $shiftEnd) {
                            $q->where('start_time', '<=', $shiftStart)
                                ->where('end_time', '>=', $shiftEnd);
                        });
                })
                ->orderBy('adjusted_duration', 'desc')
                ->paginate(self::TOP_X_RECORDS, ['*'], 'top_'.$state);
        }

        // Filter the reportData to only include the states that are in the $states array
        $chartData = array_filter($reportData, function ($item) use ($states) {
            return in_array($item['state'], $states); // Access array keys
        });

        // Return the filtered data along with the full report data to the view
        return view('pulse::livewire.pith.pith-report', ['reportData' => $reportData, 'chartData' => $chartData, 'ebUnits' => $this->ebUnits, 'ebAmount' => $this->ebAmount]);
    }
}
