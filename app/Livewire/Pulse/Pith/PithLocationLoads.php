<?php

namespace App\Livewire\Pulse\Pith;

use App\Http\Controllers\Pulse\Api\PithProductionInventoryController;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithWatering;
use App\Models\Team;
use App\Services\Pulse\PithLocationLedgerService;
use App\Services\Pulse\PithStockTransferService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The location panel beside the inventory list: the open batch (or a closed one, read-only),
 * its inward/outward/watering entries, and the Stock In / Stock Out / Watering, delete, close
 * and past-batch popups.
 *
 * Every write re-checks its own capability and is scoped to this location, which mount()
 * has already verified belongs to the active team. After a change the panel dispatches
 * `pith-inventory-refresh` so the list and totals beside it update, and `pi-toast`.
 */
class PithLocationLoads extends Component
{
    /** @var array<string, array{create: string, edit: string, delete: string, label: string}> */
    private const KINDS = [
        'in' => ['create' => 'pith_inventory_inward.create', 'edit' => 'pith_inventory_inward.edit', 'delete' => 'pith_inventory_inward.delete', 'label' => 'Stock In'],
        'out' => ['create' => 'pith_inventory_outward.create', 'edit' => 'pith_inventory_outward.edit', 'delete' => 'pith_inventory_outward.delete', 'label' => 'Stock Out'],
        'water' => ['create' => 'pith_watering.create', 'edit' => 'pith_watering.edit', 'delete' => 'pith_watering.delete', 'label' => 'Watering'],
    ];

    /** Both set once in mount() after checking the location is the team's; the client can't change them. */
    #[Locked]
    public $locationId;

    #[Locked]
    public $teamId;

    public $locationName;

    public $locationIsActive = true;

    /** Closed batch being viewed (its depletion id); null is the open batch. */
    public ?int $viewDepletionId = null;

    /** 'all', 'stock' or 'water'. */
    public string $entryFilter = 'all';

    /** Entry form: 'in', 'out' or 'water' while open. */
    public ?string $formType = null;

    public ?int $formEntryId = null;

    public string $formQty = '';

    public string $formDate = '';

    /** @var array{kind: string, id: int, qty: float|int, date: string}|null */
    public ?array $deletingEntry = null;

    /** @var array{id: int, qty: float|int, date: string, direction: string, peer: string|null}|null */
    public ?array $deletingTransfer = null;

    public bool $showCloseDialog = false;

    public bool $showPastBatches = false;

    public function mount($locationId, $teamId = null)
    {
        $currentTeam = $teamId
            ? Team::find($teamId)
            : (app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam);

        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-pith-inventory'), 403);

        $location = PithLocation::where('id', $locationId)->where('team_id', $currentTeam->id)->first();
        abort_unless($location, 404);

        $this->locationId = $location->id;
        $this->teamId = $currentTeam->id;
        $this->locationName = $location->name;
        $this->locationIsActive = (bool) $location->is_active;
    }

    public function closePanel()
    {
        $this->dispatch('close-location-panel');
    }

    /**
     * A transfer made from the page header may move this location's stock; re-read the ledger.
     */
    #[On('pith-inventory-refresh')]
    public function refreshLedger()
    {
        // Rendering re-reads the ledger; nothing else to do.
    }

    // ── Transfers ────────────────────────────────────────────────────────

    public function confirmDeleteTransfer(int $transferId)
    {
        if (! $this->canDeleteTransfer()) {
            return;
        }

        $entry = collect(app(PithLocationLedgerService::class)->currentBatch($this->location())['entries'])
            ->first(fn (array $entry) => ($entry['transfer']['id'] ?? null) === $transferId);

        if (! $entry || ! $entry['transfer']['changeable']) {
            return;
        }

        $this->deletingTransfer = [
            'id' => $transferId,
            'qty' => $entry['qty'],
            'date' => $entry['date'],
            'direction' => $entry['transfer']['direction'],
            'peer' => $entry['transfer']['peer_location_name'],
        ];
    }

    public function cancelDeleteTransfer()
    {
        $this->deletingTransfer = null;
    }

    public function deleteConfirmedTransfer(PithStockTransferService $transfers)
    {
        if (! $this->deletingTransfer) {
            return;
        }

        $transferId = $this->deletingTransfer['id'];
        $this->deletingTransfer = null;

        if (! $this->canDeleteTransfer()) {
            $this->toast(__('You are not authorized to do that.'), true);

            return;
        }

        $transfer = $transfers->findForTeam($this->teamId, $transferId);

        // A transfer is removed from its source location's panel only.
        if (! $transfer || (int) $transfer->from_pith_location_id !== (int) $this->locationId) {
            $this->toast(__('That transfer can no longer be changed.'), true);

            return;
        }

        try {
            $transfers->delete($transfer);
        } catch (ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), true);

            return;
        }

        $this->changed(__('Transfer deleted · stock returned to :location', ['location' => $transfer->fromLocation->name]));
    }

    public function filterEntries(string $filter)
    {
        $this->entryFilter = in_array($filter, ['all', 'stock', 'water'], true) ? $filter : 'all';
    }

    // ── Batches ──────────────────────────────────────────────────────────

    public function openPastBatches()
    {
        $this->showPastBatches = true;
    }

    public function closePastBatches()
    {
        $this->showPastBatches = false;
    }

    public function viewBatch(int $depletionId)
    {
        if (! app(PithLocationLedgerService::class)->pastBatch($this->location(), $depletionId)) {
            return;
        }

        $this->viewDepletionId = $depletionId;
        $this->entryFilter = 'all';
        $this->showPastBatches = false;
        $this->resetForm();
    }

    public function backToCurrent()
    {
        $this->viewDepletionId = null;
        $this->entryFilter = 'all';
    }

    // ── Entry form ───────────────────────────────────────────────────────

    public function openForm(string $type)
    {
        if (! isset(self::KINDS[$type]) || $this->viewDepletionId !== null || ! Gate::allows(self::KINDS[$type]['create'])) {
            return;
        }

        $this->resetErrorBag();
        $this->formType = $type;
        $this->formEntryId = null;
        $this->formQty = '';
        $this->formDate = now()->toDateString();
    }

    public function editEntry(string $kind, int $id)
    {
        if (! isset(self::KINDS[$kind]) || ! Gate::allows(self::KINDS[$kind]['edit'])) {
            return;
        }

        $entry = $this->currentEntry($kind, $id);

        if (! $entry || ! $entry['editable']) {
            return;
        }

        $this->resetErrorBag();
        $this->formType = $kind;
        $this->formEntryId = $id;
        $this->formQty = (string) (int) round($entry['qty']);
        $this->formDate = $entry['date'];
    }

    public function cancelForm()
    {
        $this->resetForm();
    }

    public function saveForm()
    {
        $kind = $this->formType;

        if (! isset(self::KINDS[$kind])) {
            return;
        }

        $editing = $this->formEntryId !== null;

        if (! Gate::allows(self::KINDS[$kind][$editing ? 'edit' : 'create'])) {
            $this->toast(__('You are not authorized to do that.'), true);

            return;
        }

        $this->formQty = preg_replace('/\D/', '', $this->formQty);

        $validated = $this->validate([
            'formQty' => ['required', 'integer', 'min:1', 'max:999999'],
            'formDate' => ['required', 'date_format:Y-m-d'],
        ], [
            'formQty.required' => __('Enter a quantity.'),
            'formQty.min' => __('Enter a quantity of at least 1.'),
            'formQty.max' => __('The quantity cannot exceed 999999.'),
            'formDate.required' => __('Pick a date.'),
            'formDate.date_format' => __('Pick a valid date.'),
        ]);

        $qty = (int) $validated['formQty'];
        $date = $validated['formDate'];

        if ($editing) {
            $record = $this->editableRecord($kind, $this->formEntryId);

            if (! $record) {
                $this->resetForm();
                $this->toast(__('That entry can no longer be changed.'), true);

                return;
            }

            $record->update(['date' => $date] + $this->quantityColumn($kind, $qty));
        } elseif ($kind === 'water') {
            PithWatering::create(['pith_location_id' => $this->locationId, 'date' => $date, 'litres' => $qty]);
        } else {
            PithInventory::create(['pith_location_id' => $this->locationId, 'date' => $date] + $this->quantityColumn($kind, $qty));
        }

        $this->resetForm();
        $this->changed($editing ? __('Entry updated') : __(':kind saved', ['kind' => __(self::KINDS[$kind]['label'])]));
    }

    // ── Delete ───────────────────────────────────────────────────────────

    public function confirmDeleteEntry(string $kind, int $id)
    {
        if (! isset(self::KINDS[$kind]) || ! Gate::allows(self::KINDS[$kind]['delete'])) {
            return;
        }

        $entry = $this->currentEntry($kind, $id);

        if (! $entry || ! $entry['editable']) {
            return;
        }

        $this->deletingEntry = ['kind' => $kind, 'id' => $id, 'qty' => $entry['qty'], 'date' => $entry['date']];
    }

    public function cancelDeleteEntry()
    {
        $this->deletingEntry = null;
    }

    public function deleteConfirmedEntry()
    {
        if (! $this->deletingEntry) {
            return;
        }

        ['kind' => $kind, 'id' => $id] = $this->deletingEntry;
        $this->deletingEntry = null;

        if (! Gate::allows(self::KINDS[$kind]['delete'])) {
            $this->toast(__('You are not authorized to do that.'), true);

            return;
        }

        $record = $this->editableRecord($kind, $id);

        if (! $record) {
            $this->toast(__('That entry can no longer be changed.'), true);

            return;
        }

        $record->delete();

        $this->changed(__(':kind entry deleted', ['kind' => $this->entryLabel($kind)]));
    }

    // ── Close batch ──────────────────────────────────────────────────────

    public function confirmCloseLocation()
    {
        if (! Gate::allows('close-location-stock') || $this->viewDepletionId !== null) {
            return;
        }

        if ($this->hasStockEntries(app(PithLocationLedgerService::class)->currentBatch($this->location()))) {
            $this->showCloseDialog = true;
        }
    }

    public function cancelClose()
    {
        $this->showCloseDialog = false;
    }

    public function closeLocationStock()
    {
        $this->showCloseDialog = false;

        if (! Gate::allows('close-location-stock')) {
            $this->toast(__('You are not authorized to close location stock.'), true);

            return;
        }

        $batchNumber = app(PithLocationLedgerService::class)->closedBatchCount($this->location()) + 1;
        $response = (new PithProductionInventoryController)->closeLocationStock($this->locationId);

        if ($response->status() !== 200) {
            $this->toast(__('Failed to close location stock.'), true);

            return;
        }

        $this->entryFilter = 'all';

        if (($response->getData()->message ?? null) === 'Location stock is already closed.') {
            $this->viewDepletionId = null;
            $this->changed(__('Location stock is already closed.'));

            return;
        }

        // Stay on the batch just closed, now read-only; "Back to current batch" opens the new one.
        $this->viewDepletionId = $this->location()->pithStockDepletions()->max('id');

        $this->changed(__('Batch :number closed · moved to Past Batches', ['number' => $batchNumber]));
    }

    public function render(PithLocationLedgerService $ledger)
    {
        $location = $this->location();
        $pastBatches = $ledger->pastBatches($location);

        $batch = $this->viewDepletionId !== null
            ? $ledger->pastBatch($location, $this->viewDepletionId)
            : null;

        if (! $batch) {
            $this->viewDepletionId = null;
            $batch = $ledger->currentBatch($location);
        }

        $entries = collect($batch['entries'])
            ->filter(fn (array $entry) => match ($this->entryFilter) {
                'stock' => $entry['kind'] !== 'water',
                'water' => $entry['kind'] === 'water',
                default => true,
            })
            ->values();

        $user = Auth::user();

        return view('pulse::livewire.pith.pith-location-loads', [
            'batch' => $batch,
            'entries' => $entries,
            'pastBatches' => $pastBatches,
            'isEmptyBatch' => $batch['is_current'] && $batch['entries'] === [],
            'canCloseBatch' => $batch['is_current'] && $this->hasStockEntries($batch) && $user->canCloseLocationStock(),
            'can' => [
                'in' => ['create' => $user->allows('pith_inventory_inward', 'create'), 'edit' => $user->allows('pith_inventory_inward', 'edit'), 'delete' => $user->allows('pith_inventory_inward', 'delete')],
                'out' => ['create' => $user->allows('pith_inventory_outward', 'create'), 'edit' => $user->allows('pith_inventory_outward', 'edit'), 'delete' => $user->allows('pith_inventory_outward', 'delete')],
                'water' => ['create' => $user->allows('pith_watering', 'create'), 'edit' => $user->allows('pith_watering', 'edit'), 'delete' => $user->allows('pith_watering', 'delete')],
            ],
            'formEntry' => $this->formEntryId !== null ? $this->currentEntry($this->formType, $this->formEntryId) : null,
            'canTransfer' => $user->allows('pith_inventory_outward', 'create') && $user->allows('pith_inventory_inward', 'create'),
            'canEditTransfer' => $user->allows('pith_inventory_outward', 'edit') && $user->allows('pith_inventory_inward', 'edit'),
            'canDeleteTransfer' => $this->canDeleteTransfer(),
        ]);
    }

    private function canDeleteTransfer(): bool
    {
        return Gate::allows('pith_inventory_outward.delete') && Gate::allows('pith_inventory_inward.delete');
    }

    private function location(): PithLocation
    {
        return PithLocation::where('id', $this->locationId)->where('team_id', $this->teamId)->firstOrFail();
    }

    /**
     * An entry of the open batch, as the ledger shows it.
     *
     * @return array<string, mixed>|null
     */
    private function currentEntry(?string $kind, int $id): ?array
    {
        return collect(app(PithLocationLedgerService::class)->currentBatch($this->location())['entries'])
            ->first(fn (array $entry) => $entry['kind'] === $kind && $entry['id'] === $id);
    }

    /**
     * The record behind an editable entry of the open batch — never a production-assigned
     * load, an archived one, or another location's.
     */
    private function editableRecord(string $kind, int $id): PithInventory|PithWatering|null
    {
        $entry = $this->currentEntry($kind, $id);

        if (! $entry || ! $entry['editable']) {
            return null;
        }

        return $kind === 'water'
            ? PithWatering::where('pith_location_id', $this->locationId)->currentBatch()->find($id)
            : PithInventory::where('pith_location_id', $this->locationId)->where('is_archived', 0)->whereNull('pith_production_id')->find($id);
    }

    /**
     * @return array<string, int>
     */
    private function quantityColumn(string $kind, int $qty): array
    {
        return match ($kind) {
            'in' => ['cft_offloaded' => $qty],
            'out' => ['taken_cft_calculated' => $qty],
            'water' => ['litres' => $qty],
        };
    }

    private function entryLabel(string $kind): string
    {
        return match ($kind) {
            'in' => __('Inward'),
            'out' => __('Outward'),
            'water' => __('Watering'),
        };
    }

    /**
     * Closing needs loads to archive; a batch holding only waterings has nothing to close.
     *
     * @param  array{entries: list<array<string, mixed>>}  $batch
     */
    private function hasStockEntries(array $batch): bool
    {
        return collect($batch['entries'])->contains(fn (array $entry) => $entry['kind'] !== 'water');
    }

    private function resetForm(): void
    {
        $this->formType = null;
        $this->formEntryId = null;
        $this->formQty = '';
        $this->formDate = '';
        $this->resetErrorBag();
    }

    private function changed(string $message): void
    {
        $this->dispatch('pith-inventory-refresh');
        $this->toast($message);
    }

    private function toast(string $message, bool $isError = false): void
    {
        $this->dispatch('pi-toast', message: $message, error: $isError);
    }
}
