<?php

namespace App\Livewire\Pulse\Pith;

use App\Livewire\Traits\WithAdvancedFilters;
use App\Models\Pulse\DeviceDataState;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithProduction;
use App\Models\Pulse\Shift;
use App\Models\Settings;
use App\Services\NumberFormatterService;
use App\Traits\ManagePithInventory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PithProductionList extends Component
{
    use ManagePithInventory;
    use WithAdvancedFilters;
    use WithPagination;

    public const STAFF_UNASSIGNED = 0;

    public $perPage = 25;

    public $teamId;

    public $showActuals = true;

    public $shifts;

    public $staffs;

    public $productionUser;

    public $search = '';

    // --- Applied advanced filters (URL-persisted) ---

    #[Url(except: '')]
    public string $datePreset = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    #[Url(except: [])]
    public array $shiftIds = [];

    #[Url(except: [])]
    public array $staffIds = [];

    #[Url(except: '')]
    public string $outMin = '';

    #[Url(except: '')]
    public string $outMax = '';

    #[Url(except: [])]
    public array $variance = [];

    #[Url(except: '')]
    public string $invMin = '';

    #[Url(except: '')]
    public string $invMax = '';

    #[Url(except: '')]
    public string $ebMin = '';

    #[Url(except: '')]
    public string $ebMax = '';

    #[Url(except: '')]
    public string $updatedFrom = '';

    #[Url(except: '')]
    public string $updatedTo = '';

    #[Url(except: '')]
    public string $prodHours = '';

    // --- Draft (popover only; not in URL) ---

    public string $draftDatePreset = '';

    public string $draftDateFrom = '';

    public string $draftDateTo = '';

    public array $draftShiftIds = [];

    public array $draftStaffIds = [];

    public string $draftOutMin = '';

    public string $draftOutMax = '';

    public array $draftVariance = [];

    public string $draftInvMin = '';

    public string $draftInvMax = '';

    public string $draftEbMin = '';

    public string $draftEbMax = '';

    public string $draftUpdatedFrom = '';

    public string $draftUpdatedTo = '';

    public string $draftProdHours = '';

    public $sortColumn = 'shift_date';

    public $sortDirection = 'desc';

    public $productionId;

    public $productionShiftDate;

    public $productionShiftName;

    public $productionLoadsTotal;

    public $ebUnits;

    public $stateDurations = [];

    public $selectedPith = null;

    public $pithLocations;

    public $selectedPithLocations;

    public $pithLocationLoads;

    public $showInventoryModal = false;

    // New properties for confirmation dialog
    public $showDeleteConfirmationDialog = false;

    public $productionIdToDelete;

    public $loadsExpected;

    public $productionUnit = 'loads';

    public $cftPerLoad = 0.0;

    // Statistics properties
    public $todayProduction = 0;

    public $weekProduction = 0;

    public $monthProduction = 0;

    public $avgEfficiency = 0;

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-pith-production'), 403);

        $this->teamId = $currentTeam->id;
        $this->showActuals = $currentTeam->showActuals();
        $this->staffs = $currentTeam->staffs;

        $this->shifts = Shift::where('team_id', $this->teamId)->get();

        $setting = Settings::where('team_id', $this->teamId)
            ->where('module', 'pith')
            ->where('key', 'PRODUCTION_UNIT')
            ->first();
        if ($setting) {
            $this->productionUnit = strtolower((string) ($setting->value ?? 'loads')) === 'cft' ? 'cft' : 'loads';
            $this->cftPerLoad = (float) ($setting->value_remark ?? 0);
        }

        $this->syncDraftFromApplied();
        $this->calculateStatistics();
    }

    public function calculateStatistics()
    {
        // Today's production
        $this->todayProduction = PithProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', today())
            ->sum('cft_produced');

        // This week's production
        $this->weekProduction = PithProduction::where('team_id', $this->teamId)
            ->whereBetween('shift_date', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('cft_produced');

        // This month's production
        $this->monthProduction = PithProduction::where('team_id', $this->teamId)
            ->whereMonth('shift_date', now()->month)
            ->whereYear('shift_date', now()->year)
            ->sum('cft_produced');

        // Average efficiency (loads produced vs expected)
        $productions = PithProduction::where('team_id', $this->teamId)
            ->whereMonth('shift_date', now()->month)
            ->whereYear('shift_date', now()->year)
            ->get();

        if ($productions->count() > 0) {
            $totalProduced = $productions->sum('cft_produced');
            $totalExpected = $productions->sum('cft_expected');
            $this->avgEfficiency = $totalExpected > 0 ? round(($totalProduced / $totalExpected) * 100, 1) : 0;
        }
    }

    public function selectProduction($productionId)
    {
        $this->selectedPith = PithProduction::find($productionId);

        if (! $this->selectedPith) {
            $this->ebUnits = 0;
            $this->pithLocations = [];

            return;
        }

        $this->ebUnits = $this->selectedPith->eb_units;

        // Fetch the target loads_expected
        $this->loadsExpected = $this->selectedPith->cft_expected;

        // Fetch locations with loads for the given productionId
        $this->selectedPithLocations = PithLocation::join('pulse_pith_inventory', 'pulse_pith_locations.id', '=', 'pulse_pith_inventory.pith_location_id')
            ->where('pulse_pith_inventory.pith_production_id', $productionId)
            ->where('pulse_pith_locations.team_id', $this->teamId)
            ->select('pulse_pith_locations.*', DB::raw('SUM(pulse_pith_inventory.cft_offloaded) as total_loads'))
            ->groupBy('pulse_pith_locations.id')
            ->having('total_loads', '>', 0)
            ->orderBy('pulse_pith_locations.name', 'asc')
            ->get();

        $shiftStart = $this->selectedPith->start_time;
        $shiftEnd = $this->selectedPith->end_time;

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
                                ) as total_duration')
        )
            ->where('device_id', $this->selectedPith->device_id)
            ->where(function ($query) use ($shiftStart, $shiftEnd) {
                $query->whereBetween('start_time', [$shiftStart, $shiftEnd])
                    ->orWhereBetween('end_time', [$shiftStart, $shiftEnd])
                    ->orWhere(function ($q) use ($shiftStart, $shiftEnd) {
                        $q->where('start_time', '<=', $shiftStart)
                            ->where('end_time', '>=', $shiftEnd);
                    });
            })
            ->groupBy('state')
            ->get()
            ->keyBy('state')
            ->toArray();

        // Prepare state durations data
        $states = ['efficient', 'off', 'shutdown', 'overload', 'idle', 'missing'];
        $stateDurations = [];
        $numberFormatterService = new NumberFormatterService;

        foreach ($states as $state) {
            $stateDurations[$state] = isset($reportData[$state])
                ? $numberFormatterService->formatToFriendlyDuration($reportData[$state]['total_duration'])
                : 0;
        }

        // Pass this variable to your Blade view
        $this->stateDurations = $stateDurations;
    }

    public function backToTable()
    {
        $this->selectedPith = null;
    }

    // Method to open the inventory modal and load locations data
    public function openInventoryModal($productionId, $productionShiftName)
    {
        // The blade hides this action from readers, but a hidden button is not a
        // permission — the Livewire endpoint is callable on its own.
        if (! Gate::allows('pith_production.edit')) {
            session()->flash('error', 'You are not authorized to manage pith inventory');

            return;
        }

        $this->productionId = $productionId;
        $this->productionShiftName = $productionShiftName;

        $inventoryData = $this->fetchInventoryData($productionId, $this->teamId);

        $this->productionShiftDate = $inventoryData['productionShiftDate'];
        $this->productionLoadsTotal = $inventoryData['productionLoadsTotal'];
        $this->pithLocations = $inventoryData['pithLocations'];
        $this->pithLocationLoads = $inventoryData['pithLocationLoads'];

        $this->showInventoryModal = true;
        $this->selectProduction($productionId);
    }

    // Method to update the loads distributed across locations
    public function updateLoads()
    {
        // The write behind the inventory modal, reachable without ever opening it.
        if (! Gate::allows('pith_production.edit')) {
            session()->flash('error', 'You are not authorized to manage pith inventory');

            return;
        }

        $result = $this->updateInventoryLoads(
            $this->productionId,
            $this->pithLocationLoads,
            $this->productionLoadsTotal,
            $this->productionShiftDate
        );

        if (isset($result['error'])) {
            $this->addError('totalError', $result['error']);

            return;
        }

        // Flash a success message to the session
        $this->dispatch('pith-production-stored');
        $this->showInventoryModal = false;
        $this->selectProduction($this->selectedPith->id);
    }

    public function updatedPithLocationLoads()
    {
        $loads = array_column($this->pithLocationLoads, 'cft_offloaded');
        foreach ($loads as &$load) {
            $load = is_numeric($load) ? (float) $load : 0;
        }
        $computedTotal = array_sum($loads);

        if ($computedTotal > $this->productionLoadsTotal) {
            $this->addError('totalError', 'Error: Total distributed CFT exceeds CFT produced.');
        } else {
            $this->resetErrorBag('totalError');
        }
    }

    // Open the delete confirmation dialog
    public function confirmDeletion($id)
    {
        // Find the production record and eager load the 'user' and 'shift' relationships
        $production = PithProduction::with('staff')->find($id);

        if ($production) {
            $this->productionIdToDelete = $id;
            $this->productionShiftName = $production->shift_name;
            $this->productionShiftDate = $production->shift_date;

            // Make sure $production->user is an object, not a string
            if ($production->staff) {
                $this->productionUser = $production->staff->staff_name;
            } else {
                $this->productionUser = 'Unknown';
            }

            $this->showDeleteConfirmationDialog = true;
        }
    }

    // Method to close the modal
    public function closeInventoryModal()
    {
        $this->showInventoryModal = false;
        $this->selectProduction($this->selectedPith->id);
    }

    public function deleteProduction()
    {
        if (! Gate::allows('pith_production.delete')) {
            session()->flash('error', 'You are not authorized to delete production records');

            return;
        }

        if (! $this->productionIdToDelete) {
            return response()->json(['error' => 'Invalid production ID'], 400);
        }

        // Attempt deletion using model instance
        $deleted = false;

        // Get the object first to deletes the linked records (ex: pith loads) as part of Eloquent model
        $production = PithProduction::where('id', $this->productionIdToDelete)
            ->where('team_id', $this->teamId)
            ->first();

        if ($production) {
            if (! $production->canDeleteRecord()) {
                session()->flash('error', 'This pith production record is locked or outside the allowed delete window.');
                $this->productionIdToDelete = null;
                $this->showDeleteConfirmationDialog = false;

                return;
            }

            $deleted = $production->delete();
        }

        // Reset dialog visibility
        $this->productionIdToDelete = null;
        $this->showDeleteConfirmationDialog = false;

        if (! $deleted) {
            session()->flash('error', 'No matching record found to delete.');
            Log::warning('Delete failed: No record matched.', ['productionId' => $this->productionIdToDelete, 'teamId' => $this->teamId]);
        } else {
            session()->flash('message', 'Pith production deleted successfully.');
        }

        // Redirect to pith.index page
        return redirect()->route('pith.index', ['team' => $this->teamId]);
    }

    public function closeDeleteModal()
    {
        $this->productionIdToDelete = null;
        $this->showDeleteConfirmationDialog = false;
    }

    // -------------------------------------------------------------------------
    // Advanced filters
    // -------------------------------------------------------------------------

    public function syncDraftFromApplied(): void
    {
        $this->draftDatePreset = $this->datePreset;
        $this->draftDateFrom = $this->dateFrom;
        $this->draftDateTo = $this->dateTo;
        $this->draftShiftIds = array_map('intval', $this->shiftIds);
        $this->draftStaffIds = array_map('intval', $this->staffIds);
        $this->draftOutMin = $this->outMin;
        $this->draftOutMax = $this->outMax;
        $this->draftVariance = $this->variance;
        $this->draftInvMin = $this->invMin;
        $this->draftInvMax = $this->invMax;
        $this->draftEbMin = $this->ebMin;
        $this->draftEbMax = $this->ebMax;
        $this->draftUpdatedFrom = $this->updatedFrom;
        $this->draftUpdatedTo = $this->updatedTo;
        $this->draftProdHours = $this->prodHours;
    }

    public function copyDraftToApplied(): void
    {
        $this->datePreset = $this->draftDatePreset;
        $this->dateFrom = $this->draftDateFrom;
        $this->dateTo = $this->draftDateTo;
        $this->shiftIds = array_values(array_map('intval', $this->draftShiftIds));
        $this->staffIds = array_values(array_map('intval', $this->draftStaffIds));
        $this->outMin = $this->draftOutMin;
        $this->outMax = $this->draftOutMax;
        $this->variance = array_values($this->draftVariance);
        $this->invMin = $this->draftInvMin;
        $this->invMax = $this->draftInvMax;
        $this->ebMin = $this->draftEbMin;
        $this->ebMax = $this->draftEbMax;
        $this->updatedFrom = $this->draftUpdatedFrom;
        $this->updatedTo = $this->draftUpdatedTo;
        $this->prodHours = $this->draftProdHours;
    }

    public function clearFilterValues(): void
    {
        $this->datePreset = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->shiftIds = [];
        $this->staffIds = [];
        $this->outMin = '';
        $this->outMax = '';
        $this->variance = [];
        $this->invMin = '';
        $this->invMax = '';
        $this->ebMin = '';
        $this->ebMax = '';
        $this->updatedFrom = '';
        $this->updatedTo = '';
        $this->prodHours = '';
        $this->syncDraftFromApplied();
    }

    public function clearFilterChip(string $key): void
    {
        switch ($key) {
            case 'date':
                $this->datePreset = '';
                $this->dateFrom = '';
                $this->dateTo = '';
                break;
            case 'shift':
                $this->shiftIds = [];
                break;
            case 'staff':
                $this->staffIds = [];
                break;
            case 'output':
                $this->outMin = '';
                $this->outMax = '';
                break;
            case 'variance':
                $this->variance = [];
                break;
            case 'inventory':
                $this->invMin = '';
                $this->invMax = '';
                break;
            case 'eb':
                $this->ebMin = '';
                $this->ebMax = '';
                break;
            case 'updated':
                $this->updatedFrom = '';
                $this->updatedTo = '';
                break;
            case 'prodHours':
                $this->prodHours = '';
                break;
        }
        $this->syncDraftFromApplied();
    }

    public function setDraftDatePreset(string $preset): void
    {
        $this->draftDatePreset = $preset;
        if ($preset !== 'custom') {
            $this->draftDateFrom = '';
            $this->draftDateTo = '';
        }
    }

    public function toggleDraftShift(int $shiftId): void
    {
        $ids = array_map('intval', $this->draftShiftIds);
        if (in_array($shiftId, $ids, true)) {
            $this->draftShiftIds = array_values(array_filter($ids, fn ($id) => $id !== $shiftId));
        } else {
            $ids[] = $shiftId;
            $this->draftShiftIds = array_values($ids);
        }
    }

    public function toggleDraftStaff(int $staffId): void
    {
        $ids = array_map('intval', $this->draftStaffIds);
        if (in_array($staffId, $ids, true)) {
            $this->draftStaffIds = array_values(array_filter($ids, fn ($id) => $id !== $staffId));
        } else {
            $ids[] = $staffId;
            $this->draftStaffIds = array_values($ids);
        }
    }

    public function toggleDraftVariance(string $value): void
    {
        if (! in_array($value, ['over', 'under'], true)) {
            return;
        }
        if (in_array($value, $this->draftVariance, true)) {
            $this->draftVariance = array_values(array_filter($this->draftVariance, fn ($v) => $v !== $value));
        } else {
            $this->draftVariance[] = $value;
        }
    }

    public function activeFilterCount(): int
    {
        return count($this->activeFilterChips());
    }

    public function categoryHasValue(string $category, bool $useDraft = false): bool
    {
        $s = $this->filterSnapshot($useDraft);

        return match ($category) {
            'date' => ($s['datePreset'] !== '' && $s['datePreset'] !== null)
                || $s['shiftIds'] !== [],
            'staff' => $s['staffIds'] !== [],
            'output' => $s['outMin'] !== '' || $s['outMax'] !== '' || $s['variance'] !== [],
            'inventory' => $s['invMin'] !== '' || $s['invMax'] !== '' || $s['ebMin'] !== '' || $s['ebMax'] !== '',
            'meta' => $s['updatedFrom'] !== '' || $s['updatedTo'] !== '' || $s['prodHours'] !== '',
            default => false,
        };
    }

    /**
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public function activeFilterChips(): array
    {
        $chips = [];
        $s = $this->filterSnapshot(false);

        if ($s['datePreset'] !== '') {
            $label = match ($s['datePreset']) {
                'today' => 'Today',
                'week' => 'This week',
                'month' => 'This month',
                '30d' => 'Last 30 days',
                'custom' => trim(($s['dateFrom'] ?: '…').' – '.($s['dateTo'] ?: '…')),
                default => $s['datePreset'],
            };
            $chips[] = ['key' => 'date', 'label' => 'Date', 'value' => $label];
        }

        if ($s['shiftIds'] !== []) {
            $names = collect($this->shifts)
                ->whereIn('id', $s['shiftIds'])
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            $chips[] = ['key' => 'shift', 'label' => 'Shift', 'value' => implode(', ', $names) ?: implode(', ', $s['shiftIds'])];
        }

        if ($s['staffIds'] !== []) {
            $names = [];
            foreach ($s['staffIds'] as $id) {
                if ((int) $id === self::STAFF_UNASSIGNED) {
                    $names[] = 'Unassigned';

                    continue;
                }
                $staff = collect($this->staffs)->firstWhere('id', (int) $id);
                $names[] = $staff?->staff_name ?? (string) $id;
            }
            $chips[] = ['key' => 'staff', 'label' => 'Staff', 'value' => implode(', ', $names)];
        }

        if ($s['outMin'] !== '' || $s['outMax'] !== '') {
            $chips[] = [
                'key' => 'output',
                'label' => 'Output',
                'value' => ($s['outMin'] !== '' ? $s['outMin'] : '0').'–'.($s['outMax'] !== '' ? $s['outMax'] : '∞').' CFT',
            ];
        }

        if ($s['variance'] !== []) {
            $parts = array_map(fn ($v) => $v === 'over' ? 'Over estimate' : 'Under estimate', $s['variance']);
            $chips[] = ['key' => 'variance', 'label' => 'Variance', 'value' => implode(', ', $parts)];
        }

        if ($s['invMin'] !== '' || $s['invMax'] !== '') {
            $chips[] = [
                'key' => 'inventory',
                'label' => 'Inventory',
                'value' => ($s['invMin'] !== '' ? $s['invMin'] : '0').'–'.($s['invMax'] !== '' ? $s['invMax'] : '∞').' CFT',
            ];
        }

        if ($s['ebMin'] !== '' || $s['ebMax'] !== '') {
            $chips[] = [
                'key' => 'eb',
                'label' => 'EB/CFT',
                'value' => '₹'.($s['ebMin'] !== '' ? $s['ebMin'] : '0').'–₹'.($s['ebMax'] !== '' ? $s['ebMax'] : '∞'),
            ];
        }

        if ($s['updatedFrom'] !== '' || $s['updatedTo'] !== '') {
            $chips[] = [
                'key' => 'updated',
                'label' => 'Updated',
                'value' => ($s['updatedFrom'] ?: '…').' – '.($s['updatedTo'] ?: '…'),
            ];
        }

        if ($s['prodHours'] !== '') {
            $chips[] = [
                'key' => 'prodHours',
                'label' => 'Prod. hours',
                'value' => $s['prodHours'] === 'logged' ? 'Logged' : 'Not logged',
            ];
        }

        return $chips;
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterSnapshot(bool $useDraft): array
    {
        if ($useDraft) {
            return [
                'datePreset' => $this->draftDatePreset,
                'dateFrom' => $this->draftDateFrom,
                'dateTo' => $this->draftDateTo,
                'shiftIds' => array_map('intval', $this->draftShiftIds),
                'staffIds' => array_map('intval', $this->draftStaffIds),
                'outMin' => $this->draftOutMin,
                'outMax' => $this->draftOutMax,
                'variance' => $this->draftVariance,
                'invMin' => $this->draftInvMin,
                'invMax' => $this->draftInvMax,
                'ebMin' => $this->draftEbMin,
                'ebMax' => $this->draftEbMax,
                'updatedFrom' => $this->draftUpdatedFrom,
                'updatedTo' => $this->draftUpdatedTo,
                'prodHours' => $this->draftProdHours,
                'logic' => $this->filterLogic,
            ];
        }

        return [
            'datePreset' => $this->datePreset,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'shiftIds' => array_map('intval', $this->shiftIds),
            'staffIds' => array_map('intval', $this->staffIds),
            'outMin' => $this->outMin,
            'outMax' => $this->outMax,
            'variance' => $this->variance,
            'invMin' => $this->invMin,
            'invMax' => $this->invMax,
            'ebMin' => $this->ebMin,
            'ebMax' => $this->ebMax,
            'updatedFrom' => $this->updatedFrom,
            'updatedTo' => $this->updatedTo,
            'prodHours' => $this->prodHours,
            'logic' => $this->filterLogic,
        ];
    }

    /**
     * Build constraint closures from a filter snapshot.
     *
     * @param  array<string, mixed>  $s
     * @return array<int, callable>
     */
    protected function pithFilterConstraints(array $s): array
    {
        $constraints = [];
        $table = 'pulse_summary_pith_productions';

        $dateRange = $this->resolveDatePresetRange(
            $s['datePreset'] ?: null,
            $s['dateFrom'] ?: null,
            $s['dateTo'] ?: null
        );
        if ($dateRange) {
            [$from, $to] = $dateRange;
            $constraints[] = function ($q) use ($table, $from, $to) {
                $q->whereBetween("{$table}.shift_date", [$from, $to]);
            };
        }

        if ($s['shiftIds'] !== []) {
            $ids = $s['shiftIds'];
            $constraints[] = function ($q) use ($table, $ids) {
                $q->whereIn("{$table}.shift_id", $ids);
            };
        }

        if ($s['staffIds'] !== []) {
            $ids = $s['staffIds'];
            $includeUnassigned = in_array(self::STAFF_UNASSIGNED, $ids, true);
            $realIds = array_values(array_filter($ids, fn ($id) => (int) $id !== self::STAFF_UNASSIGNED));
            $constraints[] = function ($q) use ($table, $includeUnassigned, $realIds) {
                $q->where(function ($inner) use ($table, $includeUnassigned, $realIds) {
                    if ($realIds !== []) {
                        $inner->whereIn("{$table}.staff_id", $realIds);
                    }
                    if ($includeUnassigned) {
                        $realIds !== []
                            ? $inner->orWhereNull("{$table}.staff_id")
                            : $inner->whereNull("{$table}.staff_id");
                    }
                });
            };
        }

        if ($s['outMin'] !== '' || $s['outMax'] !== '') {
            $min = $s['outMin'];
            $max = $s['outMax'];
            $constraints[] = function ($q) use ($table, $min, $max) {
                if ($min !== '') {
                    $q->where("{$table}.cft_produced", '>=', (float) $min);
                }
                if ($max !== '') {
                    $q->where("{$table}.cft_produced", '<=', (float) $max);
                }
            };
        }

        if ($s['variance'] !== []) {
            $variance = $s['variance'];
            $constraints[] = function ($q) use ($table, $variance) {
                $q->where("{$table}.cft_expected", '>', 0)
                    ->where(function ($inner) use ($table, $variance) {
                        $first = true;
                        if (in_array('over', $variance, true)) {
                            $inner->whereColumn("{$table}.cft_produced", '>', "{$table}.cft_expected");
                            $first = false;
                        }
                        if (in_array('under', $variance, true)) {
                            if ($first) {
                                $inner->whereColumn("{$table}.cft_produced", '<', "{$table}.cft_expected");
                            } else {
                                $inner->orWhereColumn("{$table}.cft_produced", '<', "{$table}.cft_expected");
                            }
                        }
                    });
            };
        }

        if ($s['invMin'] !== '' || $s['invMax'] !== '') {
            $min = $s['invMin'];
            $max = $s['invMax'];
            $constraints[] = function ($q) use ($table, $min, $max) {
                $sub = '(SELECT COALESCE(SUM(pulse_pith_inventory.cft_offloaded), 0)
                    FROM pulse_pith_inventory
                    WHERE pulse_pith_inventory.pith_production_id = '.$table.'.id)';
                if ($min !== '') {
                    $q->whereRaw("{$sub} >= ?", [(float) $min]);
                }
                if ($max !== '') {
                    $q->whereRaw("{$sub} <= ?", [(float) $max]);
                }
            };
        }

        if ($s['ebMin'] !== '' || $s['ebMax'] !== '') {
            $min = $s['ebMin'];
            $max = $s['ebMax'];
            $constraints[] = function ($q) use ($table, $min, $max) {
                $q->where("{$table}.eb_price", '>', 0)
                    ->where("{$table}.cft_produced", '>', 0);
                $expr = "({$table}.eb_price / NULLIF({$table}.cft_produced, 0))";
                if ($min !== '') {
                    $q->whereRaw("{$expr} >= ?", [(float) $min]);
                }
                if ($max !== '') {
                    $q->whereRaw("{$expr} <= ?", [(float) $max]);
                }
            };
        }

        if ($s['updatedFrom'] !== '' || $s['updatedTo'] !== '') {
            $from = $s['updatedFrom'];
            $to = $s['updatedTo'];
            $constraints[] = function ($q) use ($table, $from, $to) {
                if ($from !== '') {
                    $q->whereDate("{$table}.updated_at", '>=', $from);
                }
                if ($to !== '') {
                    $q->whereDate("{$table}.updated_at", '<=', $to);
                }
            };
        }

        if ($s['prodHours'] === 'logged') {
            $constraints[] = function ($q) use ($table) {
                $q->whereNotNull("{$table}.load_hrs")->where("{$table}.load_hrs", '>', 0);
            };
        } elseif ($s['prodHours'] === 'not_logged') {
            $constraints[] = function ($q) use ($table) {
                $q->where(function ($inner) use ($table) {
                    $inner->whereNull("{$table}.load_hrs")
                        ->orWhere("{$table}.load_hrs", '<=', 0);
                });
            };
        }

        return $constraints;
    }

    protected function applyPithAdvancedFilters($query, bool $useDraft = false)
    {
        $s = $this->filterSnapshot($useDraft);
        $constraints = $this->pithFilterConstraints($s);

        return $this->applyConstraintGroup($query, $constraints, $s['logic'] ?? $this->filterLogic);
    }

    protected function basePithQuery()
    {
        return PithProduction::query()
            ->where('team_id', $this->teamId)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('pulse_summary_pith_productions.shift_date', 'like', $this->search.'%')
                        ->orWhere('pulse_summary_pith_productions.cft_produced', '=', $this->search)
                        ->orWhere('pulse_summary_pith_productions.start_time', 'like', $this->search.'%')
                        ->orWhere('pulse_summary_pith_productions.end_time', 'like', $this->search.'%')
                        ->orWhere('pulse_summary_pith_productions.created_at', 'like', $this->search.'%')
                        ->orWhere('pulse_summary_pith_productions.updated_at', 'like', $this->search.'%')
                        ->orWhereHas('staff', function ($q) {
                            $q->where('staff_name', 'like', $this->search.'%');
                        })
                        ->orWhereHas('shift', function ($q) {
                            $q->where('name', 'like', '%'.$this->search.'%');
                        });
                });
            });
    }

    // Method to handle sorting
    public function sortBy($column)
    {
        if ($column === 'loads_produced') {
            $column = 'cft_produced';
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'desc';
        }
    }

    public function updatedPerPage($value)
    {
        $this->perPage = $value;
    }

    public function updatingPerPage()
    {
        $this->resetPage(); // Reset to page 1 when perPage changes
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $base = $this->basePithQuery();

        $totalForFilters = (clone $base)->count();

        $filtered = $this->applyPithAdvancedFilters(clone $base, false);
        $draftFiltered = $this->applyPithAdvancedFilters(clone $base, true);

        $matchCount = (clone $draftFiltered)->count();
        $appliedMatchCount = (clone $filtered)->count();

        $productions = (clone $filtered)
            ->with(['staff:id,staff_name'])
            ->orderBy($this->sortColumn, $this->sortDirection)
            ->orderBy('shift_id', 'desc')
            ->select('pulse_summary_pith_productions.*')
            ->paginate($this->perPage);

        $dateFormat = config('app.date_format');
        $timeFormat = config('app.time_format');
        $dateTimeFormat = config('app.date_time_format');
        $entryPolicy = PithProduction::entryPolicyFor((int) $this->teamId);

        $filterChips = $this->activeFilterChips();
        $filterCategories = [
            'date' => 'Date & shift',
            'staff' => 'Staff',
            'output' => 'Output & variance',
            'inventory' => 'Inventory & cost',
        ];
        $categoryDots = collect(array_keys($filterCategories))
            ->mapWithKeys(fn ($key) => [$key => $this->categoryHasValue($key, true)])
            ->all();

        return view('pulse::livewire.pith.pith-production-list',
            compact(
                'productions',
                'dateFormat',
                'timeFormat',
                'dateTimeFormat',
                'entryPolicy',
                'filterChips',
                'filterCategories',
                'categoryDots',
                'matchCount',
                'totalForFilters',
                'appliedMatchCount'
            ));
    }
}
