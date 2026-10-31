<?php

namespace App\Livewire\Pulse\Pith;

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Services\NumberFormatterService;
use App\Services\Pulse\PithStockTransferService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Transfer Pith" popup on the inventory page. Opened from the page header, where the
 * source defaults to the team's first active location, or from a location's panel, where the
 * source is that location. The quantity is a whole number of cft, like the Stock In/Out forms.
 * Like Stock Out, a transfer is not capped at the source's stock in hand — going over shows an
 * advisory only, matching PithStockTransferService, which carries no availability rule either.
 *
 * From/To are grouped by the team's location groups, in the same order as the inventory list.
 *
 * After a transfer it dispatches `pith-inventory-refresh` so the list and panel update, and
 * `pi-toast`.
 */
class PithStockTransfer extends Component
{
    /** Set once in mount() from the page, which has already checked the team; the client can't change it. */
    #[Locked]
    public $teamId;

    public bool $open = false;

    /** Opened from a location panel: the source is that location and can't be changed. */
    public bool $fromLocked = false;

    /** Untyped: the selects send ids as strings and "" for no choice; read through locationIdOf(). */
    public $fromLocationId = null;

    public $toLocationId = null;

    public string $qty = '';

    public string $date = '';

    public string $note = '';

    /** The transfer being edited, or null for a new one. Set only by editTransfer(). */
    #[Locked]
    public ?int $editingTransferId = null;

    /** Where the edited transfer sent its stock, and how much; undone in the preview. */
    #[Locked]
    public ?int $editingOriginalToId = null;

    #[Locked]
    public float $editingOriginalCft = 0;

    public function mount($teamId)
    {
        $this->teamId = (int) $teamId;
    }

    #[On('open-pith-transfer')]
    public function openTransfer(?int $fromLocationId = null)
    {
        if (! $this->canTransfer()) {
            $this->toast(__('You are not authorized to transfer pith stock.'), true);

            return;
        }

        $locations = $this->locations();
        $from = $fromLocationId !== null ? $locations->firstWhere('id', $fromLocationId) : null;

        if ($fromLocationId !== null && ! $from) {
            return;
        }

        $from ??= $locations->first(fn (array $location) => $location['is_active']) ?? $locations->first();

        if (! $from) {
            $this->toast(__('Add a storage location before transferring stock.'), true);

            return;
        }

        $this->resetErrorBag();
        $this->resetEditing();
        $this->fromLocked = $fromLocationId !== null;
        $this->fromLocationId = $from['id'];
        $this->toLocationId = $locations->first(fn (array $location) => $location['id'] !== $from['id'] && $location['is_active'])['id'] ?? null;
        $this->qty = '';
        $this->date = now()->toDateString();
        $this->note = '';
        $this->open = true;
    }

    /**
     * Opens the popup on an existing transfer. Its source stays fixed; the destination,
     * quantity, date and comment can change. A quantity entered with decimals through the API
     * is shown rounded, since this form takes whole cft. Transfers are edited from their source location only,
     * so a request naming another location is refused.
     */
    #[On('edit-pith-transfer')]
    public function editTransfer(int $transferId, PithStockTransferService $transfers, ?int $locationId = null)
    {
        if (! $this->canEditTransfer()) {
            $this->toast(__('You are not authorized to edit a transfer.'), true);

            return;
        }

        $transfer = $transfers->findForTeam($this->teamId, $transferId);

        if (! $transfer
            || ($locationId !== null && $locationId !== (int) $transfer->from_pith_location_id)
            || $transfer->loads()->where('is_archived', 1)->exists()) {
            $this->toast(__('That transfer can no longer be changed.'), true);

            return;
        }

        $this->resetErrorBag();
        $this->editingTransferId = $transfer->id;
        $this->editingOriginalToId = (int) $transfer->to_pith_location_id;
        $this->editingOriginalCft = (float) $transfer->cft;
        $this->fromLocked = true;
        $this->fromLocationId = (int) $transfer->from_pith_location_id;
        $this->toLocationId = (int) $transfer->to_pith_location_id;
        $this->qty = (string) (int) round((float) $transfer->cft);
        $this->date = $transfer->date->format('Y-m-d');
        $this->note = (string) $transfer->note;
        $this->open = true;
    }

    public function cancel()
    {
        $this->open = false;
        $this->resetErrorBag();
        $this->resetEditing();
    }

    public function updatedFromLocationId()
    {
        if ($this->fromLocked) {
            return;
        }

        if ($this->locationIdOf($this->toLocationId) === $this->locationIdOf($this->fromLocationId)) {
            $this->toLocationId = null;
        }

        $this->resetErrorBag();
    }

    public function updatedToLocationId()
    {
        $this->resetErrorBag('toLocationId');
    }

    /**
     * Only from the header, where either side may be picked; the panel's source is fixed.
     */
    public function swap()
    {
        if ($this->fromLocked || ! $this->locationIdOf($this->fromLocationId) || ! $this->locationIdOf($this->toLocationId)) {
            return;
        }

        [$this->fromLocationId, $this->toLocationId] = [$this->toLocationId, $this->fromLocationId];
        $this->qty = '';
        $this->resetErrorBag();
    }

    public function save(PithStockTransferService $transfers)
    {
        $editing = $this->editingTransferId !== null;

        if (! ($editing ? $this->canEditTransfer() : $this->canTransfer())) {
            $this->toast(__('You are not authorized to do that.'), true);

            return;
        }

        $this->qty = preg_replace('/\D/', '', $this->qty);

        $this->validate([
            'fromLocationId' => ['required', 'integer'],
            'toLocationId' => ['required', 'integer', 'different:fromLocationId'],
            'qty' => ['required', 'integer', 'min:1', 'max:'.PithStockTransferService::MAX_CFT],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'fromLocationId.required' => __('Choose the location to transfer from.'),
            'toLocationId.required' => __('Choose the location to transfer to.'),
            'toLocationId.different' => __('Source and destination locations cannot be the same.'),
            'qty.required' => __('Enter a quantity.'),
            'qty.integer' => __('Enter a whole number of cft.'),
            'qty.min' => __('Transfer quantity must be at least 1 cft.'),
            'qty.max' => __('The quantity cannot exceed :max.', ['max' => PithStockTransferService::MAX_CFT]),
            'date.required' => __('Pick a date.'),
            'date.date_format' => __('Pick a valid date.'),
            'date.before_or_equal' => __('The date cannot be in the future.'),
            'note.max' => __('Comments cannot exceed 255 characters.'),
        ]);

        try {
            if ($editing) {
                $existing = $transfers->findForTeam($this->teamId, $this->editingTransferId);

                if (! $existing) {
                    $this->cancel();
                    $this->toast(__('That transfer can no longer be changed.'), true);

                    return;
                }

                $transfer = $transfers->update(
                    transfer: $existing,
                    toLocationId: (int) $this->locationIdOf($this->toLocationId),
                    cft: (float) $this->qty,
                    date: $this->date,
                    note: $this->note,
                );
            } else {
                $transfer = $transfers->transfer(
                    teamId: $this->teamId,
                    fromLocationId: $this->locationIdOf($this->fromLocationId),
                    toLocationId: $this->locationIdOf($this->toLocationId),
                    cft: (float) $this->qty,
                    date: $this->date,
                    note: $this->note,
                    userId: Auth::id(),
                );
            }
        } catch (ValidationException $e) {
            // The service names fields as the API does; show them against this form's inputs.
            $fields = ['from_location_id' => 'fromLocationId', 'to_location_id' => 'toLocationId', 'cft' => 'qty'];

            foreach ($e->errors() as $field => $messages) {
                $this->addError($fields[$field] ?? 'qty', $messages[0]);
            }

            return;
        }

        $this->open = false;
        $this->resetEditing();
        $this->dispatch('pith-inventory-refresh');
        $this->toast(__($editing ? 'Transfer updated · :qty cft from :from to :to' : 'Transferred :qty cft from :from to :to', [
            'qty' => NumberFormatterService::formatToIndianFormat(round((float) $transfer->cft), false, 0),
            'from' => $transfer->fromLocation->name,
            'to' => $transfer->toLocation->name,
        ]));
    }

    public function render()
    {
        $locations = $this->open ? $this->locationsForForm() : collect();
        $fromId = $this->locationIdOf($this->fromLocationId);

        return view('pulse::livewire.pith.pith-stock-transfer', [
            'locations' => $locations,
            'fromGroups' => $this->optionGroups($locations->filter(
                fn (array $location) => $location['is_active'] || $location['stock'] != 0.0 || $location['id'] === $fromId
            )),
            'toGroups' => $this->optionGroups($locations->filter(
                fn (array $location) => $location['is_active'] && $location['id'] !== $fromId
            )),
            'isEditing' => $this->editingTransferId !== null,
        ]);
    }

    /**
     * The locations as the form should see them. While editing, the transfer being changed is
     * undone first: its quantity is back at the source and gone from its old destination, so
     * "Available" and the before -> after preview read the same as for a new transfer. The old
     * destination stays offered even if it has since been deactivated.
     *
     * @return Collection<int, array{id: int, name: string, group: string|null, group_id: int, group_order: int, is_active: bool, stock: float}>
     */
    private function locationsForForm(): Collection
    {
        $locations = $this->locations();

        if ($this->editingTransferId === null) {
            return $locations;
        }

        return $locations->map(function (array $location) {
            if ($location['id'] === $this->locationIdOf($this->fromLocationId)) {
                $location['stock'] = round($location['stock'] + $this->editingOriginalCft, 2);
            }

            if ($location['id'] === $this->editingOriginalToId) {
                $location['stock'] = round($location['stock'] - $this->editingOriginalCft, 2);
                $location['is_active'] = true;
            }

            return $location;
        });
    }

    /**
     * All of the team's locations, active or not, with their open-batch stock. Eligibility as a
     * From/To option is decided by render(), not here — this is the raw set editTransfer() and
     * locationsForForm() work from.
     *
     * @return Collection<int, array{id: int, name: string, group: string|null, group_id: int, group_order: int, is_active: bool, stock: float}>
     */
    private function locations(): Collection
    {
        $stock = PithInventory::query()
            ->join('pulse_pith_locations', 'pulse_pith_locations.id', '=', 'pulse_pith_inventory.pith_location_id')
            ->where('pulse_pith_locations.team_id', $this->teamId)
            ->where('pulse_pith_inventory.is_archived', 0)
            ->groupBy('pulse_pith_inventory.pith_location_id')
            ->selectRaw('pulse_pith_inventory.pith_location_id as location_id, COALESCE(SUM(cft_offloaded), 0) - COALESCE(SUM(taken_cft_calculated), 0) as stock')
            ->pluck('stock', 'location_id');

        return PithLocation::where('pulse_pith_locations.team_id', $this->teamId)
            ->leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
            ->orderBy('pulse_pith_locations.name')
            ->get([
                'pulse_pith_locations.id',
                'pulse_pith_locations.name',
                'pulse_pith_locations.is_active',
                'pulse_pith_locations.group_id',
                'pulse_pith_location_groups.name as group_name',
                'pulse_pith_location_groups.display_order as group_order',
            ])
            ->map(fn (PithLocation $location) => [
                'id' => (int) $location->id,
                'name' => $location->name,
                'group' => $location->group_name,
                'group_id' => (int) ($location->group_id ?? 0),
                'group_order' => (int) ($location->group_order ?? 0),
                'is_active' => (bool) $location->is_active,
                'stock' => round((float) ($stock[$location->id] ?? 0), 2),
            ])
            ->values();
    }

    /**
     * Eligible options folded into their location groups, for the From/To <optgroup>s.
     * Ungrouped locations collect under id 0 and sort last, matching the inventory list.
     *
     * @param  Collection<int, array<string, mixed>>  $options
     * @return Collection<int, array{id: int, name: string, is_ungrouped: bool, order: int, locations: Collection<int, array<string, mixed>>}>
     */
    private function optionGroups(Collection $options): Collection
    {
        return $options
            ->groupBy(fn (array $location) => $location['group_id'])
            ->map(fn (Collection $locations, $groupId) => [
                'id' => (int) $groupId,
                'name' => (int) $groupId === 0 ? __('Ungrouped') : ($locations->first()['group'] ?? __('Ungrouped')),
                'is_ungrouped' => (int) $groupId === 0,
                'order' => (int) ($locations->first()['group_order'] ?? 0),
                'locations' => $locations->values(),
            ])
            ->sortBy([
                fn ($a, $b) => $a['is_ungrouped'] <=> $b['is_ungrouped'],
                fn ($a, $b) => $a['order'] <=> $b['order'],
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();
    }

    private function locationIdOf($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function resetEditing(): void
    {
        $this->editingTransferId = null;
        $this->editingOriginalToId = null;
        $this->editingOriginalCft = 0;
    }

    private function canEditTransfer(): bool
    {
        return Gate::allows('view-pith-inventory')
            && Gate::allows('pith_inventory_outward.edit')
            && Gate::allows('pith_inventory_inward.edit');
    }

    private function canTransfer(): bool
    {
        return Gate::allows('view-pith-inventory')
            && Gate::allows('pith_inventory_outward.create')
            && Gate::allows('pith_inventory_inward.create');
    }

    private function toast(string $message, bool $isError = false): void
    {
        $this->dispatch('pi-toast', message: $message, error: $isError);
    }
}
