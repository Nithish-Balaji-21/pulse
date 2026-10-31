<?php

namespace App\Livewire;

use App\Models\Team;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class SuperadminFactories extends Component
{
    use WithPagination;

    public $perPage = 25;

    // Input fields (bound to form inputs — don't trigger search)
    public $searchName = '';

    public $searchAddress = '';

    public $searchModule = '';

    public $searchStatus = '';

    // Applied fields (used in query — only updated on Search click)
    public $appliedName = '';

    public $appliedAddress = '';

    public $appliedModule = '';

    public $appliedStatus = '';

    public $sortColumn = 'is_active';

    public $sortDirection = 'desc';

    private array $modulesMap = [
        'has_pith_module' => 'Pith',
        'has_pith_module_bales' => 'Mini Fibre Bales',
        'has_pith_module_blocks' => 'Pith Blocks',
        'has_fibre_module' => 'Fibre',
        'has_growbags_module' => 'Growbag',
        'has_fibre_module_weight' => 'Fibre Weight',
        'has_water_meter_module' => 'Water Meter',
        'has_attendance_module' => 'Staff Attendance',
        'has_geofencing_module' => 'Geofencing',
    ];

    private array $jsonPaths = [
        'has_pith_module' => 'modules->pulse->modules->pith->is_active',
        'has_pith_module_bales' => 'modules->pulse->modules->pith->submodules->bales',
        'has_pith_module_blocks' => 'modules->pulse->modules->pith->submodules->blocks',
        'has_fibre_module' => 'modules->pulse->modules->fibre->is_active',
        'has_growbags_module' => 'modules->pulse->modules->growbags->is_active',
        'has_fibre_module_weight' => 'modules->pulse->modules->fibre->submodules->weight',
        'has_water_meter_module' => 'modules->pulse->modules->water_meter->is_active',
        'has_attendance_module' => 'modules->pulse->modules->attendance->is_active',
        'has_geofencing_module' => 'modules->pulse->modules->geofencing->is_active',
    ];

    public function doSearch()
    {
        // Copy input values → applied values, then reset to page 1
        $this->appliedName = $this->searchName;
        $this->appliedAddress = $this->searchAddress;
        $this->appliedModule = $this->searchModule;
        $this->appliedStatus = $this->searchStatus;
        $this->resetPage();
    }

    public function sortBy($column)
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function resetSearch()
    {
        // Clear both input and applied values
        $this->searchName = '';
        $this->searchAddress = '';
        $this->searchModule = '';
        $this->searchStatus = '';
        $this->appliedName = '';
        $this->appliedAddress = '';
        $this->appliedModule = '';
        $this->appliedStatus = '';
        $this->sortColumn = 'is_active';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function render()
    {
        $query = Team::with('shifts')->withCount(['devices', 'users', 'staffs']);

        // Use APPLIED values (not raw input values) for filtering
        $query->when($this->appliedName, function ($q) {
            $q->where('name', 'like', "%{$this->appliedName}%");
        });

        $query->when($this->appliedAddress, function ($q) {
            $q->whereHas('address', function ($q2) {
                $q2->where('address_line_1', 'like', "%{$this->appliedAddress}%")
                    ->orWhere('pincode', 'like', "%{$this->appliedAddress}%");
            });
        });

        $query->when($this->appliedModule, function ($q) {
            $q->whereHas('pulse', function ($qPulse) {
                $preceedingFields = collect($this->modulesMap)
                    ->keys()
                    ->takeUntil(fn ($field) => $field === $this->appliedModule);
                foreach ($preceedingFields as $field) {
                    if (isset($this->jsonPaths[$field])) {
                        $qPulse->where($this->jsonPaths[$field], false);
                    }
                }
                if (isset($this->jsonPaths[$this->appliedModule])) {
                    $qPulse->where($this->jsonPaths[$this->appliedModule], true);
                }
            });
        });

        $query->when($this->appliedStatus, function ($q) {
            $q->whereHas('pulse', function ($q2) {
                $q2->where('is_active', $this->appliedStatus === 'active' ? 1 : 0);
            });
        });

        $sortable = ['name', 'users_count', 'staffs_count', 'created_at'];
        if (in_array($this->sortColumn, $sortable)) {
            $query->orderBy($this->sortColumn, $this->sortDirection);
        } else {
            // Sort by relations using subqueries or join would be complex here,
            // but we can sort the collection later or skip for now if not explicitly requested
            // Default sorting just by id if it's the default
            $query->orderBy('id', 'desc');
        }

        $factories = $query->paginate($this->perPage)->through(function ($factory) {
            $addressText = trim(($factory->address->address_line_1 ?? '').' '.($factory->address->pincode ?? ''));

            return [
                'id' => $factory->id,
                'name' => $factory->name,
                'address' => $addressText,
                'coordinates' => trim($factory->pulse->coordinates ?? ''),
                'shift_start_time' => optional($factory->shifts->first(), function ($shift) {
                    return Carbon::createFromFormat('H:i:s', $shift->start_time)->format(config('app.time_format'));
                }),
                'devices' => $factory->devices_count,
                'main_module' => $this->getMainModule($factory),
                'other_modules' => $this->getOtherModules($factory),
                'status' => ($factory->pulse->is_active ?? false) ? 'Active' : 'Inactive',
                'validity' => ($factory->pulse->license_valid_till ?? false) ? Carbon::parse($factory->pulse->license_valid_till)->format(config('app.date_format')) : null,
                'users' => $factory->users_count,
                'staff' => $factory->staffs_count,
                'created' => $factory->created_at->format(config('app.date_format')),
            ];
        });

        if (in_array($this->sortColumn, ['shift_start_time', 'main_module'])) {
            $sorted = $factories->getCollection()
                ->sortBy($this->sortColumn, SORT_REGULAR, $this->sortDirection === 'desc');
            $factories->setCollection($sorted->values());
        }

        return view('livewire.all-factories', compact('factories'));
    }

    private function getMainModule($factory): string
    {
        foreach ($this->modulesMap as $field => $label) {
            if ($factory->$field) {
                return $label;
            }
        }

        return '-';
    }

    private function getOtherModules($factory): array
    {
        $mainModule = $this->getMainModule($factory);

        return collect($this->modulesMap)
            ->filter(fn ($label, $field) => $factory->$field && $label !== $mainModule)
            ->values()
            ->all();
    }
}
