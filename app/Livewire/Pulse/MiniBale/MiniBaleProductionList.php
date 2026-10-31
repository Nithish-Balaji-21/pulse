<?php

namespace App\Livewire\Pulse\MiniBale;

use App\Livewire\Traits\WithAdvancedFilters;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Staff;
use App\Services\NumberFormatterService;
use App\Services\Pulse\ProductionEntryPolicy;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MiniBaleProductionList extends Component
{
    use WithAdvancedFilters;
    use WithPagination;

    public const STAFF_UNASSIGNED = 0;

    public $perPage = 25;

    public $search = '';

    public $staffs;

    public $machines = [];

    public $teamId;

    public $sortColumn = 'shift_date';

    public $sortDirection = 'desc';

    // --- Applied advanced filters (URL-persisted) ---

    #[Url(except: '')]
    public string $datePreset = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    #[Url(except: [])]
    public array $staffIds = [];

    #[Url(except: [])]
    public array $machineIds = [];

    #[Url(except: '')]
    public string $outMin = '';

    #[Url(except: '')]
    public string $outMax = '';

    #[Url(except: [])]
    public array $variance = [];

    #[Url(except: '')]
    public string $stockMin = '';

    #[Url(except: '')]
    public string $stockMax = '';

    #[Url(except: '')]
    public string $soldMin = '';

    #[Url(except: '')]
    public string $soldMax = '';

    #[Url(except: '')]
    public string $updatedFrom = '';

    #[Url(except: '')]
    public string $updatedTo = '';

    // --- Draft ---

    public string $draftDatePreset = '';

    public string $draftDateFrom = '';

    public string $draftDateTo = '';

    public array $draftStaffIds = [];

    public array $draftMachineIds = [];

    public string $draftOutMin = '';

    public string $draftOutMax = '';

    public array $draftVariance = [];

    public string $draftStockMin = '';

    public string $draftStockMax = '';

    public string $draftSoldMin = '';

    public string $draftSoldMax = '';

    public string $draftUpdatedFrom = '';

    public string $draftUpdatedTo = '';

    public $showDeleteConfirmationDialog = false;

    public $deleteProductionId;

    public $showStockDialog = false;

    public $stockDialogMode = 'sold';

    public $stockDialogProductionId;

    public $stockDialogShiftDate;

    public $stockDialogOpening = 0;

    public $stockDialogProduced = 0;

    public $soldQuantity = 0;

    public $correctionType = 'add';

    public $correctionAmount;

    public $correctionComment;

    public $lastEntryText;

    public $dateFormat;

    public $showActuals = true;

    /**
     * @var array{staff: bool, machine: bool, stock: bool, comments: bool}
     */
    public $columns = [
        'staff' => true,
        'machine' => true,
        'stock' => true,
        'comments' => true,
    ];

    public function mount()
    {
        $team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $team || ! $team->has_pith_module || ! $team->has_pith_module_bales) {
            return redirect("/{$team->id}/dashboard");
        }
        abort_unless(Gate::allows('view-fibre-bales'), 403);

        $this->teamId = $team->id;
        $this->showActuals = $team->showActuals();
        $this->staffs = Staff::where('team_id', $this->teamId)->get();
        $this->dateFormat = config('app.date_format');
        $this->columns = $this->resolveColumns();

        $this->machines = DB::table('pulse_master_minibale_machines')
            ->where('team_id', $this->teamId)
            ->where('is_active', 1)
            ->orderBy('machine_name')
            ->get()
            ->toArray();

        $this->syncDraftFromApplied();
    }

    /**
     * @return array{staff: bool, machine: bool, stock: bool, comments: bool}
     */
    protected function resolveColumns(): array
    {
        $counts = MiniBaleProduction::where('team_id', $this->teamId)
            ->selectRaw('COUNT(staff_id) AS staff_count')
            ->selectRaw('COUNT(minibale_machine_id) AS machine_count')
            ->selectRaw("SUM(CASE WHEN comments IS NULL OR comments = '' THEN 0 ELSE 1 END) AS comment_count")
            ->first();

        $hasStock = MiniBaleProduction::where('team_id', $this->teamId)
            ->where(function ($query) {
                $query->whereNotNull('sold')
                    ->orWhere('correction', '!=', 0)
                    ->orWhere('bales_in_stock', '!=', 0);
            })
            ->exists();

        return [
            'staff' => (int) ($counts->staff_count ?? 0) > 0,
            'machine' => (int) ($counts->machine_count ?? 0) > 0,
            'stock' => $hasStock,
            'comments' => (int) ($counts->comment_count ?? 0) > 0,
        ];
    }

    protected function stockLevels($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $levels = [];
        foreach ($rows as $row) {
            $levels[$row->id] = [
                'value' => (float) ($row->bales_in_stock ?? 0),
                'date' => $row->shift_date,
                'recorded' => $row->sold !== null || (int) ($row->correction ?? 0) !== 0,
            ];
        }

        return $levels;
    }

    /**
     * Machine pill strip: immediate applied filter (single machine or all).
     */
    public function filterByMachine($machineId)
    {
        if ($machineId === null || $machineId === '') {
            $this->machineIds = [];
        } else {
            $this->machineIds = [(int) $machineId];
        }
        $this->draftMachineIds = $this->machineIds;
        $this->resetPage();
    }

    public function confirmDeletion($id)
    {
        $this->deleteProductionId = $id;
        $this->showDeleteConfirmationDialog = true;
    }

    public function closeDeleteModal()
    {
        $this->showDeleteConfirmationDialog = false;
    }

    public function deleteProduction()
    {
        if (! Gate::allows('fibre_bales.delete')) {
            session()->flash('error', 'You are not authorized to delete fibre production records');

            return;
        }

        if (! $this->deleteProductionId) {
            return;
        }

        $production = MiniBaleProduction::where('id', $this->deleteProductionId)
            ->where('team_id', $this->teamId)
            ->first();

        if (! $production) {
            return;
        }

        if (! $production->canDeleteRecord()) {
            session()->flash('error', 'This fibre bales record is locked or outside the allowed delete window.');
            $this->closeDeleteModal();

            return;
        }

        $production->delete();

        session()->flash('message', 'Fibre production deleted successfully.');
        $this->closeDeleteModal();

        return redirect()->route('fibre.index', ['team' => $this->teamId]);
    }

    public function openSoldDialog(int $id): void
    {
        $this->openStockDialog($id, 'sold');
    }

    public function openCorrectionDialog(int $id): void
    {
        $this->openStockDialog($id, 'correction');
    }

    public function closeStockDialog(): void
    {
        $this->reset([
            'showStockDialog',
            'stockDialogProductionId',
            'stockDialogShiftDate',
            'stockDialogOpening',
            'stockDialogProduced',
            'soldQuantity',
            'correctionAmount',
            'correctionComment',
            'lastEntryText',
        ]);

        $this->stockDialogMode = 'sold';
        $this->correctionType = 'add';
        $this->resetErrorBag([
            'soldQuantity',
            'correctionType',
            'correctionAmount',
            'correctionComment',
            'stockDialogProduction',
        ]);
    }

    public function saveStockDialog()
    {
        if (! Gate::allows('fibre_bales.edit')) {
            session()->flash('error', 'You are not authorized to update fibre bales stock.');

            return;
        }

        $production = MiniBaleProduction::where('team_id', $this->teamId)
            ->find($this->stockDialogProductionId);

        if (! $production || ! $production->canEditRecord()) {
            $this->addError('stockDialogProduction', 'This fibre bales record is locked or outside the edit window.');

            return;
        }

        $this->validate([
            'soldQuantity' => 'required|integer|min:0',
            'correctionType' => 'required|in:add,deduct',
            'correctionAmount' => 'nullable|integer|min:0',
            'correctionComment' => 'nullable|string|max:150',
        ]);

        $signedCorrection = ($this->correctionAmount ?? 0) > 0
            ? ($this->correctionType === 'deduct' ? -1 : 1) * (int) $this->correctionAmount
            : 0;

        $production->update([
            'sold' => (int) $this->soldQuantity,
            'correction' => $signedCorrection,
            'correction_comment' => $this->correctionComment,
        ]);

        $this->columns = $this->resolveColumns();
        $this->closeStockDialog();
        session()->flash('message', 'Fibre Bales stock updated successfully.');
    }

    public function sortBy($column)
    {
        $map = [
            'date' => 'shift_date',
            'shift_date' => 'shift_date',
            'machine_name' => 'machine_name',
            'output' => 'output',
            'bales_produced' => 'bales_produced',
            'bales_expected' => 'bales_expected',
            'bales_in_stock' => 'bales_in_stock',
            'sold' => 'sold',
            'correction' => 'correction',
            'staff_id' => 'staff_id',
            'staff_name' => 'staff_id',
            'id' => 'id',
        ];

        $mapped = $map[$column] ?? 'shift_date';

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

    public function updatingSearch()
    {
        $this->resetPage();
    }

    private function openStockDialog(int $id, string $mode): void
    {
        if (! Gate::allows('fibre_bales.edit')) {
            session()->flash('error', 'You are not authorized to edit fibre bales stock.');

            return;
        }

        $production = MiniBaleProduction::where('team_id', $this->teamId)->find($id);

        if (! $production || ! $production->canEditRecord()) {
            session()->flash('error', 'This fibre bales record is locked or outside the edit window.');

            return;
        }

        $dateString = Carbon::parse($production->shift_date)->toDateString();
        $previous = MiniBaleProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', '<', $dateString)
            ->orderByDesc('shift_date')
            ->first();

        $lastSale = MiniBaleProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', '<', $dateString)
            ->where('sold', '>', 0)
            ->orderByDesc('shift_date')
            ->first();

        $this->resetErrorBag([
            'soldQuantity',
            'correctionType',
            'correctionAmount',
            'correctionComment',
            'stockDialogProduction',
        ]);

        $this->stockDialogMode = $mode;
        $this->stockDialogProductionId = $production->id;
        $this->stockDialogShiftDate = $dateString;
        $this->stockDialogOpening = (int) ($previous?->bales_in_stock ?? 0);
        $this->stockDialogProduced = $production->producedForStock();
        $this->soldQuantity = $production->sold ?? 0;

        $correction = (int) ($production->correction ?? 0);
        $this->correctionType = $correction < 0 ? 'deduct' : 'add';
        $this->correctionAmount = $correction !== 0 ? abs($correction) : null;
        $this->correctionComment = $production->correction_comment;
        $this->lastEntryText = $lastSale
            ? 'Last sale: '.NumberFormatterService::formatToIndianFormat($lastSale->sold, false, 0)
                .' bales on '.Carbon::parse($lastSale->shift_date)->format('d/m')
            : null;
        $this->showStockDialog = true;
    }

    // -------------------------------------------------------------------------
    // Advanced filters
    // -------------------------------------------------------------------------

    public function syncDraftFromApplied(): void
    {
        $this->draftDatePreset = $this->datePreset;
        $this->draftDateFrom = $this->dateFrom;
        $this->draftDateTo = $this->dateTo;
        $this->draftStaffIds = array_map('intval', $this->staffIds);
        $this->draftMachineIds = array_map('intval', $this->machineIds);
        $this->draftOutMin = $this->outMin;
        $this->draftOutMax = $this->outMax;
        $this->draftVariance = $this->variance;
        $this->draftStockMin = $this->stockMin;
        $this->draftStockMax = $this->stockMax;
        $this->draftSoldMin = $this->soldMin;
        $this->draftSoldMax = $this->soldMax;
        $this->draftUpdatedFrom = $this->updatedFrom;
        $this->draftUpdatedTo = $this->updatedTo;
    }

    public function copyDraftToApplied(): void
    {
        $this->datePreset = $this->draftDatePreset;
        $this->dateFrom = $this->draftDateFrom;
        $this->dateTo = $this->draftDateTo;
        $this->staffIds = array_values(array_map('intval', $this->draftStaffIds));
        $this->machineIds = array_values(array_map('intval', $this->draftMachineIds));
        $this->outMin = $this->draftOutMin;
        $this->outMax = $this->draftOutMax;
        $this->variance = array_values($this->draftVariance);
        $this->stockMin = $this->draftStockMin;
        $this->stockMax = $this->draftStockMax;
        $this->soldMin = $this->draftSoldMin;
        $this->soldMax = $this->draftSoldMax;
        $this->updatedFrom = $this->draftUpdatedFrom;
        $this->updatedTo = $this->draftUpdatedTo;
    }

    public function clearFilterValues(): void
    {
        $this->datePreset = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->staffIds = [];
        $this->machineIds = [];
        $this->outMin = '';
        $this->outMax = '';
        $this->variance = [];
        $this->stockMin = '';
        $this->stockMax = '';
        $this->soldMin = '';
        $this->soldMax = '';
        $this->updatedFrom = '';
        $this->updatedTo = '';
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
            case 'staff':
                $this->staffIds = [];
                break;
            case 'machine':
                $this->machineIds = [];
                break;
            case 'output':
                $this->outMin = '';
                $this->outMax = '';
                break;
            case 'variance':
                $this->variance = [];
                break;
            case 'stock':
                $this->stockMin = '';
                $this->stockMax = '';
                break;
            case 'sold':
                $this->soldMin = '';
                $this->soldMax = '';
                break;
            case 'updated':
                $this->updatedFrom = '';
                $this->updatedTo = '';
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

    public function toggleDraftMachine(int $machineId): void
    {
        $ids = array_map('intval', $this->draftMachineIds);
        if (in_array($machineId, $ids, true)) {
            $this->draftMachineIds = array_values(array_filter($ids, fn ($id) => $id !== $machineId));
        } else {
            $ids[] = $machineId;
            $this->draftMachineIds = array_values($ids);
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
            'date' => $s['datePreset'] !== '',
            'staff' => $s['staffIds'] !== [],
            'machine' => $s['machineIds'] !== [],
            'output' => $s['outMin'] !== '' || $s['outMax'] !== '' || $s['variance'] !== [],
            'stock' => $s['stockMin'] !== '' || $s['stockMax'] !== '' || $s['soldMin'] !== '' || $s['soldMax'] !== '',
            'meta' => $s['updatedFrom'] !== '' || $s['updatedTo'] !== '',
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

        if ($s['machineIds'] !== []) {
            $names = collect($this->machines)
                ->filter(fn ($m) => in_array((int) (is_array($m) ? ($m['id'] ?? 0) : $m->id), $s['machineIds'], true))
                ->map(fn ($m) => is_array($m) ? ($m['machine_name'] ?? '') : $m->machine_name)
                ->filter()
                ->values()
                ->all();
            $chips[] = ['key' => 'machine', 'label' => 'Machine', 'value' => implode(', ', $names) ?: implode(', ', $s['machineIds'])];
        }

        if ($s['outMin'] !== '' || $s['outMax'] !== '') {
            $chips[] = [
                'key' => 'output',
                'label' => 'Output',
                'value' => ($s['outMin'] !== '' ? $s['outMin'] : '0').'–'.($s['outMax'] !== '' ? $s['outMax'] : '∞').' bales',
            ];
        }

        if ($s['variance'] !== []) {
            $parts = array_map(fn ($v) => $v === 'over' ? 'Over estimate' : 'Under estimate', $s['variance']);
            $chips[] = ['key' => 'variance', 'label' => 'Variance', 'value' => implode(', ', $parts)];
        }

        if ($s['stockMin'] !== '' || $s['stockMax'] !== '') {
            $chips[] = [
                'key' => 'stock',
                'label' => 'Stock',
                'value' => ($s['stockMin'] !== '' ? $s['stockMin'] : '0').'–'.($s['stockMax'] !== '' ? $s['stockMax'] : '∞'),
            ];
        }

        if ($s['soldMin'] !== '' || $s['soldMax'] !== '') {
            $chips[] = [
                'key' => 'sold',
                'label' => 'Sold',
                'value' => ($s['soldMin'] !== '' ? $s['soldMin'] : '0').'–'.($s['soldMax'] !== '' ? $s['soldMax'] : '∞').' bales',
            ];
        }

        if ($s['updatedFrom'] !== '' || $s['updatedTo'] !== '') {
            $chips[] = [
                'key' => 'updated',
                'label' => 'Updated',
                'value' => ($s['updatedFrom'] ?: '…').' – '.($s['updatedTo'] ?: '…'),
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
                'staffIds' => array_map('intval', $this->draftStaffIds),
                'machineIds' => array_map('intval', $this->draftMachineIds),
                'outMin' => $this->draftOutMin,
                'outMax' => $this->draftOutMax,
                'variance' => $this->draftVariance,
                'stockMin' => $this->draftStockMin,
                'stockMax' => $this->draftStockMax,
                'soldMin' => $this->draftSoldMin,
                'soldMax' => $this->draftSoldMax,
                'updatedFrom' => $this->draftUpdatedFrom,
                'updatedTo' => $this->draftUpdatedTo,
                'logic' => $this->filterLogic,
            ];
        }

        return [
            'datePreset' => $this->datePreset,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'staffIds' => array_map('intval', $this->staffIds),
            'machineIds' => array_map('intval', $this->machineIds),
            'outMin' => $this->outMin,
            'outMax' => $this->outMax,
            'variance' => $this->variance,
            'stockMin' => $this->stockMin,
            'stockMax' => $this->stockMax,
            'soldMin' => $this->soldMin,
            'soldMax' => $this->soldMax,
            'updatedFrom' => $this->updatedFrom,
            'updatedTo' => $this->updatedTo,
            'logic' => $this->filterLogic,
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<int, callable>
     */
    protected function miniBaleFilterConstraints(array $s): array
    {
        $constraints = [];
        $table = 'pulse_summary_mini_bales';

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

        if ($s['machineIds'] !== []) {
            $ids = $s['machineIds'];
            $constraints[] = function ($q) use ($table, $ids) {
                $q->whereIn("{$table}.minibale_machine_id", $ids);
            };
        }

        if ($s['outMin'] !== '' || $s['outMax'] !== '') {
            $min = $s['outMin'];
            $max = $s['outMax'];
            $constraints[] = function ($q) use ($table, $min, $max) {
                if ($min !== '') {
                    $q->where("{$table}.bales_produced", '>=', (float) $min);
                }
                if ($max !== '') {
                    $q->where("{$table}.bales_produced", '<=', (float) $max);
                }
            };
        }

        if ($s['variance'] !== []) {
            $variance = $s['variance'];
            $constraints[] = function ($q) use ($table, $variance) {
                $q->where("{$table}.bales_expected", '>', 0)
                    ->where(function ($inner) use ($table, $variance) {
                        $first = true;
                        if (in_array('over', $variance, true)) {
                            $inner->whereColumn("{$table}.bales_produced", '>', "{$table}.bales_expected");
                            $first = false;
                        }
                        if (in_array('under', $variance, true)) {
                            if ($first) {
                                $inner->whereColumn("{$table}.bales_produced", '<', "{$table}.bales_expected");
                            } else {
                                $inner->orWhereColumn("{$table}.bales_produced", '<', "{$table}.bales_expected");
                            }
                        }
                    });
            };
        }

        if ($s['stockMin'] !== '' || $s['stockMax'] !== '') {
            $min = $s['stockMin'];
            $max = $s['stockMax'];
            $constraints[] = function ($q) use ($table, $min, $max) {
                if ($min !== '') {
                    $q->where("{$table}.bales_in_stock", '>=', (float) $min);
                }
                if ($max !== '') {
                    $q->where("{$table}.bales_in_stock", '<=', (float) $max);
                }
            };
        }

        if ($s['soldMin'] !== '' || $s['soldMax'] !== '') {
            $min = $s['soldMin'];
            $max = $s['soldMax'];
            $constraints[] = function ($q) use ($table, $min, $max) {
                if ($min !== '') {
                    $q->where("{$table}.sold", '>=', (float) $min);
                }
                if ($max !== '') {
                    $q->where("{$table}.sold", '<=', (float) $max);
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

        return $constraints;
    }

    protected function applyMiniBaleAdvancedFilters($query, bool $useDraft = false)
    {
        $s = $this->filterSnapshot($useDraft);

        return $this->applyConstraintGroup($query, $this->miniBaleFilterConstraints($s), $s['logic'] ?? $this->filterLogic);
    }

    protected function baseMiniBaleQuery()
    {
        return MiniBaleProduction::query()
            ->where('team_id', $this->teamId)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('pulse_summary_mini_bales.shift_date', 'like', $this->search.'%')
                        ->orWhere('pulse_summary_mini_bales.bales_produced', '=', $this->search)
                        ->orWhereHas('staff', function ($q) {
                            $q->where('staff_name', 'like', $this->search.'%');
                        });
                });
            });
    }

    public function render()
    {
        $base = $this->baseMiniBaleQuery();
        $totalForFilters = (clone $base)->count();
        $filtered = $this->applyMiniBaleAdvancedFilters(clone $base, false);
        $draftFiltered = $this->applyMiniBaleAdvancedFilters(clone $base, true);
        $matchCount = (clone $draftFiltered)->count();
        $appliedMatchCount = (clone $filtered)->count();

        $productionsQuery = (clone $filtered)->with(['staff:id,staff_name']);

        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $totals = (clone $productionsQuery)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('COUNT(*) AS shift_count')
            ->selectRaw('COALESCE(SUM(bales_expected), 0) AS estimated_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN bales_produced > 0 THEN bales_produced ELSE 0 END), 0) AS actual_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN bales_produced > 0 THEN bales_expected ELSE 0 END), 0) AS estimated_comparable')
            ->selectRaw('SUM(CASE WHEN bales_produced > 0 THEN 1 ELSE 0 END) AS actual_shift_count')
            ->first();

        $productions = $productionsQuery
            ->when(
                $this->sortColumn === 'output',
                fn ($query) => $query->orderByRaw("CASE WHEN bales_produced > 0 THEN bales_produced ELSE bales_expected END {$direction}")
            )
            ->when(
                $this->sortColumn === 'bales_in_stock',
                fn ($query) => $query->orderBy('bales_in_stock', $direction)
            )
            ->when(
                ! in_array($this->sortColumn, ['output', 'bales_in_stock'], true),
                fn ($query) => $query->orderBy($this->sortColumn, $direction)
            )
            ->paginate($this->perPage);

        $rows = $productions->getCollection();

        $barScale = $rows
            ->map(fn ($row) => max((float) $row->bales_expected, (float) $row->bales_produced))
            ->max() ?? 0;

        $entryPolicy = ProductionEntryPolicy::forTeam((int) $this->teamId, ProductionEntryPolicyKeys::BALES);

        $filterChips = $this->activeFilterChips();
        $filterCategories = [
            'date' => 'Date',
            'staff' => 'Staff',
            'output' => 'Output & variance',
            'stock' => 'Stock',
        ];
        $categoryDots = collect(array_keys($filterCategories))
            ->mapWithKeys(fn ($key) => [$key => $this->categoryHasValue($key, true)])
            ->all();

        return view('pulse::livewire.minibale.minibale-production-list', [
            'productions' => $productions,
            'machines' => $this->machines,
            'barScale' => $barScale,
            'stockLevels' => $this->columns['stock'] ? $this->stockLevels($rows) : [],
            'totals' => $totals,
            'entryPolicy' => $entryPolicy,
            'filterChips' => $filterChips,
            'filterCategories' => $filterCategories,
            'categoryDots' => $categoryDots,
            'matchCount' => $matchCount,
            'totalForFilters' => $totalForFilters,
            'appliedMatchCount' => $appliedMatchCount,
        ]);
    }
}
