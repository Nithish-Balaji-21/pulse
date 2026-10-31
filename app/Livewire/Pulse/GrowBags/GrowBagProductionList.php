<?php

namespace App\Livewire\Pulse\GrowBags;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class GrowBagProductionList extends Component
{
    use WithPagination;

    public $perPage = 25;

    public $search = '';

    public $filterDate;

    public $shifts;

    public $filterShiftId;

    public $machines = []; // all active machines for this team

    public $filterMachineId = null;

    public $teamId;

    public $sortColumn = 'shift_date';

    public $sortDirection = 'desc';

    public $dateFormat;

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;

        if (! $currentTeam || ! $currentTeam->has_growbags_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-growbag-production'), 403);

        $this->teamId = $currentTeam->id;
        $this->dateFormat = config('app.date_format');

        $this->shifts = DB::table('pulse_shifts')
            ->where('team_id', $this->teamId)
            ->where('is_active', 1)
            ->get();

        $this->machines = DB::table('pulse_master_growbag_machines')
            ->where('team_id', $this->teamId)
            ->where('is_active', 1)
            ->orderBy('machine_name')
            ->get()
            ->toArray();
    }

    public function filterByMachine($machineId)
    {
        $this->filterMachineId = $machineId; // null = All
        $this->resetPage();
    }

    public function sortBy($column)
    {
        // Map UI columns to actual DB columns to prevent invalid ORDER BY errors.
        $map = [
            'date' => 'shift_date',
            'shift_date' => 'shift_date',
            'shift_name' => 'shift_name',
            'machine_name' => 'machine_name',
            'bags_produced' => 'bags_produced',
            'eb_units' => 'eb_units',
            'eb_price' => 'eb_price',
        ];

        $mapped = $map[$column] ?? $column;

        if ($this->sortColumn === $mapped) {
            $this->sortDirection = $this->sortDirection === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortColumn = $mapped;
            $this->sortDirection = 'desc';
        }
    }

    public function updatedPerPage($value)
    {
        $this->perPage = $value;
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->search = '';
        $this->filterDate = '';
        $this->filterShiftId = '';
        $this->filterMachineId = null;
        $this->resetPage();
    }

    public function render()
    {
        $productions = DB::table('pulse_summary_growbags')
            ->where('team_id', $this->teamId)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('machine_name', 'like', '%'.$this->search.'%')
                        ->orWhere('shift_name', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->filterDate, function ($query) {
                $query->where('shift_date', 'like', $this->filterDate.'%');
            })
            ->when($this->filterShiftId, function ($query) {
                $query->where('shift_id', '=', $this->filterShiftId);
            })
            ->when($this->filterMachineId, function ($query) {
                $query->where('growbag_machine_id', $this->filterMachineId);
            })
            ->orderBy($this->sortColumn, $this->sortDirection)
            ->paginate($this->perPage);

        return view('pulse::livewire.grow-bags.grow-bag-production-list', [
            'productions' => $productions,
            'machines' => $this->machines,
        ]);
    }
}
