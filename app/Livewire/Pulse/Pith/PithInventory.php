<?php

namespace App\Livewire\Pulse\Pith;

use App\Http\Controllers\Pulse\PithInventoryController;
use App\Services\Pulse\PithInventoryExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class PithInventory extends Component
{
    public $locationsData = [];

    public $totalOffloaded = 0;

    public $totalTaken = 0;

    public $totalInStock = 0;

    public $totalOverdrawn = 0;

    public $totalWaterLitres = 0;

    public $totalWaterCycles = 0;

    public $selectedLocationId = null;

    /** Set once in mount(); every query on this page is scoped to it, so the client can't change it. */
    #[Locked]
    public $teamId;

    public $teamName;

    /** @var array<int, int> group ids currently expanded; 0 is the ungrouped bucket */
    public $expandedGroups = [];

    // Filter & Export popup: a date range previewed on screen, then exported as PDF or image
    public $showExportModal = false;

    /** 'yesterday', 'week' (the 7 days to yesterday), 'month' (1st to today) or 'custom'. */
    public $exportPreset = 'yesterday';

    public $exportStartDate;

    public $exportEndDate;

    public $exportIncludeWatering = true;

    public $exportIncludeEmpty = false;

    public $exportReportData = null;

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-pith-inventory'), 403);

        $this->teamId = $currentTeam->id;
        $this->teamName = $currentTeam->name;
        $this->index();
        $this->initializeExpandedGroups();

        // ?location= arrives from the old /pith/taken/{id} page; only this team's locations open.
        $requestedLocation = (int) request()->query('location');
        if ($requestedLocation && collect($this->locationsData)->contains(fn ($location) => (int) $location->location_id === $requestedLocation)) {
            $this->selectedLocationId = $requestedLocation;
        }

        // ?export=YYYY-MM-DD arrives from a pinned day on the history page.
        $exportDay = request()->query('export');
        if (is_string($exportDay) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $exportDay) && strtotime($exportDay)) {
            $this->showExportModal = true;
            $this->exportPreset = 'custom';
            $this->applyFilter($exportDay, $exportDay);
        }
    }

    public function index(?PithInventoryController $controller = null)
    {
        $controller = $controller ?? app(PithInventoryController::class);
        $response = $controller->index(false, $this->teamId);
        $this->locationsData = $response->getData();
        $this->total();
    }

    /**
     * The location panel changed something — re-read the list and totals beside it.
     */
    #[On('pith-inventory-refresh')]
    public function refreshInventory()
    {
        $this->index();
    }

    public function total()
    {
        $locations = collect($this->locationsData);

        $this->totalOffloaded = $locations->sum(fn ($l) => round($l->totalLoadsOffloaded));
        $this->totalTaken = $locations->sum(fn ($l) => round($l->totalLoadsTaken));
        $this->totalInStock = $locations->sum(function ($l) {
            $remaining = (float) ($l->totalLoadsOffloaded ?? 0) - (float) ($l->totalLoadsTaken ?? 0);

            return $remaining > 0 ? round($remaining, 2) : 0;
        });
        $this->totalOverdrawn = $locations->reduce(function ($carry, $l) {
            $remaining = (float) ($l->totalLoadsOffloaded ?? 0) - (float) ($l->totalLoadsTaken ?? 0);

            return $carry + ($remaining < 0 ? abs(round($remaining, 2)) : 0);
        }, 0);
        $this->totalWaterLitres = (int) $locations->sum(fn ($l) => (int) ($l->totalWaterLitres ?? 0));
        $this->totalWaterCycles = (int) $locations->sum(fn ($l) => (int) ($l->waterCycles ?? 0));
    }

    public function selectLocation($locationId)
    {
        $this->selectedLocationId = (int) $locationId;
    }

    /**
     * The detail panel owns its own header, so closing is requested from there.
     */
    #[On('close-location-panel')]
    public function backToSummary()
    {
        $this->selectedLocationId = null;
    }

    public function toggleGroup($groupId)
    {
        $groupId = (int) $groupId;

        $this->expandedGroups = in_array($groupId, $this->expandedGroups, true)
            ? array_values(array_diff($this->expandedGroups, [$groupId]))
            : array_merge($this->expandedGroups, [$groupId]);

        $this->persistExpandedGroups();
    }

    public function expandAllGroups()
    {
        $this->expandedGroups = $this->buildGroups($this->visibleLocations())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->persistExpandedGroups();
    }

    public function collapseAllGroups()
    {
        $this->expandedGroups = [];
        $this->persistExpandedGroups();
    }

    /**
     * The single "Expand all / Collapse all" link: collapse once everything is open.
     */
    public function toggleAllGroups()
    {
        $allIds = $this->buildGroups($this->visibleLocations())->pluck('id')->map(fn ($id) => (int) $id);

        $allOpen = $allIds->isNotEmpty() && $allIds->every(fn ($id) => in_array($id, $this->expandedGroups, true));

        $allOpen ? $this->collapseAllGroups() : $this->expandAllGroups();
    }

    /**
     * Opens on yesterday, matching how most exports are pulled the morning after, with that
     * day's report already loaded so the popup is never empty.
     */
    public function openExportModal()
    {
        $this->showExportModal = true;
        $this->setExportPreset('yesterday');
    }

    public function closeExportModal()
    {
        $this->showExportModal = false;
        $this->exportReportData = null;
    }

    public function setExportPreset(string $preset)
    {
        $yesterday = now()->subDay()->toDateString();

        [$start, $end] = match ($preset) {
            'week' => [now()->subDays(7)->toDateString(), $yesterday],
            'month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'custom' => [$this->exportStartDate ?? $yesterday, $this->exportEndDate ?? $yesterday],
            default => [$yesterday, $yesterday],
        };

        $this->exportPreset = in_array($preset, ['yesterday', 'week', 'month', 'custom'], true) ? $preset : 'yesterday';
        $this->applyFilter($start, $end);
    }

    /**
     * Handles the dashboard-style date range bar in the Filter & Export popup, which
     * reports its own dates directly rather than going through setExportPreset's presets.
     */
    public function applyExportRange(string $start, string $end, string $preset = 'Custom')
    {
        $this->exportPreset = 'custom';
        $this->applyFilter($start, $end);
    }

    public function updatedExportStartDate()
    {
        $this->applyCustomRange();
    }

    public function updatedExportEndDate()
    {
        $this->applyCustomRange();
    }

    public function toggleExportWatering()
    {
        // Only changes which columns the report shows, not its figures.
        $this->exportIncludeWatering = ! $this->exportIncludeWatering;
    }

    public function toggleExportEmpty()
    {
        $this->exportIncludeEmpty = ! $this->exportIncludeEmpty;
        $this->applyFilter($this->exportStartDate, $this->exportEndDate);
    }

    /**
     * Builds the same Opening/Inward/Outward/Closing/Watered report the PDF and image exports
     * use and shows it in the popup, so what the user previews is exactly what they export.
     * Dates arriving in the wrong order are swapped rather than rejected.
     */
    public function applyFilter($startDate, $endDate, ?PithInventoryExportService $exportService = null)
    {
        $this->exportStartDate = $startDate;
        $this->exportEndDate = $endDate;

        $this->validate([
            'exportStartDate' => ['required', 'date'],
            'exportEndDate' => ['required', 'date'],
        ]);

        $start = Carbon::parse($this->exportStartDate);
        $end = Carbon::parse($this->exportEndDate);

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
            $this->exportStartDate = $start->toDateString();
            $this->exportEndDate = $end->toDateString();
        }

        $this->exportReportData = ($exportService ?? app(PithInventoryExportService::class))
            ->buildReport($this->teamId, $start, $end, (bool) $this->exportIncludeEmpty);
    }

    private function applyCustomRange(): void
    {
        $this->exportPreset = 'custom';

        if (strtotime((string) $this->exportStartDate) && strtotime((string) $this->exportEndDate)) {
            $this->applyFilter($this->exportStartDate, $this->exportEndDate);
        }
    }

    /**
     * First visit opens the ungrouped bucket only, so unassigned locations stay
     * visible while real groups start collapsed on their roll-ups.
     */
    private function initializeExpandedGroups(): void
    {
        $stored = session()->get($this->expandedGroupsSessionKey());

        $this->expandedGroups = is_array($stored)
            ? array_map('intval', $stored)
            : [0];
    }

    private function persistExpandedGroups(): void
    {
        session()->put($this->expandedGroupsSessionKey(), array_values($this->expandedGroups));
    }

    private function expandedGroupsSessionKey(): string
    {
        return 'pith_inventory_expanded_groups_'.$this->teamId;
    }

    /**
     * Fold the visible locations into their groups, carrying the same inward/outward/in-stock
     * and watering figures each location row shows plus counts of children needing attention.
     * Ungrouped locations collect under id 0.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildGroups($locations)
    {
        return collect($locations)
            ->groupBy(fn ($location) => (int) ($location->group_id ?? 0))
            ->map(function ($groupLocations, $groupId) {
                $groupId = (int) $groupId;
                $first = $groupLocations->first();

                $offloaded = 0.0;
                $taken = 0.0;
                $inStock = 0.0;
                $overdrawn = 0.0;
                $waterLitres = 0;
                $lowCount = 0;
                $overdrawnCount = 0;

                foreach ($groupLocations as $location) {
                    $locationOffloaded = round((float) ($location->totalLoadsOffloaded ?? 0));
                    $locationTaken = round((float) ($location->totalLoadsTaken ?? 0));
                    $remaining = $locationOffloaded - $locationTaken;
                    $threshold = (int) ($location->low_stock_alert_value ?? 0);

                    $offloaded += $locationOffloaded;
                    $taken += $locationTaken;
                    $waterLitres += (int) ($location->totalWaterLitres ?? 0);

                    if ($remaining > 0) {
                        $inStock += $remaining;

                        if ($threshold > 0 && $remaining < $threshold) {
                            $lowCount++;
                        }
                    } elseif ($remaining < 0) {
                        $overdrawn += abs($remaining);
                        $overdrawnCount++;
                    }
                }

                return [
                    'id' => $groupId,
                    'name' => $groupId === 0 ? __('Ungrouped') : ($first->group_name ?? __('Ungrouped')),
                    'is_ungrouped' => $groupId === 0,
                    'display_order' => (int) ($first->group_display_order ?? 0),
                    'locations' => $groupLocations->values(),
                    'locationCount' => $groupLocations->count(),
                    'totalOffloaded' => $offloaded,
                    'totalTaken' => $taken,
                    'totalInStock' => $inStock,
                    'totalOverdrawn' => $overdrawn,
                    'totalWaterLitres' => $waterLitres,
                    'lowCount' => $lowCount,
                    'overdrawnCount' => $overdrawnCount,
                ];
            })
            ->sortBy([
                // Ungrouped always sinks to the bottom, then display order, then name.
                fn ($a, $b) => $a['is_ungrouped'] <=> $b['is_ungrouped'],
                fn ($a, $b) => $a['display_order'] <=> $b['display_order'],
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();
    }

    /**
     * Locations shown on the page: the active ones, plus deactivated ones that
     * still carry offloaded cft. Deactivated-and-emptied locations are left out
     * entirely — they only ever rendered as empty rows.
     */
    private function visibleLocations()
    {
        return collect($this->locationsData)
            ->filter(fn ($location) => (bool) data_get($location, 'is_active', true)
                || (float) data_get($location, 'totalLoadsOffloaded', 0) > 0)
            ->values();
    }

    public function render()
    {
        $locations = $this->visibleLocations();
        $groups = $this->buildGroups($locations);

        // Teams that have not set up any group keep a flat list rather than a single
        // meaningless "Ungrouped" accordion.
        $hasGroups = $groups->contains(fn ($group) => ! $group['is_ungrouped']);

        $user = Auth::user();

        return view('pulse::livewire.pith.pith-inventory', [
            // A transfer is an outward at one location and an inward at another.
            'canTransfer' => $user->allows('pith_inventory_outward', 'create') && $user->allows('pith_inventory_inward', 'create'),
            'canEditTransfer' => $user->allows('pith_inventory_outward', 'edit') && $user->allows('pith_inventory_inward', 'edit'),
            'locations' => $locations,
            'groups' => $groups,
            'hasGroups' => $hasGroups,
            'allGroupsExpanded' => $groups->isNotEmpty() && $groups->every(fn ($group) => in_array($group['id'], $this->expandedGroups, true)),
        ]);
    }
}
