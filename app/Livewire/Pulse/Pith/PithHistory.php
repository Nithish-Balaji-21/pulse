<?php

namespace App\Livewire\Pulse\Pith;

use App\Models\Pulse\PithInventorySummary;
use App\Models\Pulse\PithLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inventory History: a month of daily stock, inward, outward and watering for all locations,
 * and the same series per location for the ones ticked in the sidebar.
 *
 * In Stock is each location's end-of-day figure from the pulse_pith_inventory_summary snapshot,
 * which is written at 23:59, so the current month runs to yesterday; a day the snapshot hasn't
 * written carries the location's last known figure rather than reading as empty. Inward, outward and
 * watering are that day's movements, summed from the dated load and watering rows — the
 * snapshot's inward/outward columns are running totals, not daily figures.
 */
class PithHistory extends Component
{
    /** Line colours for compared locations, in the order they were ticked. */
    public const PALETTE = ['#8B2323', '#2F78A8', '#CC7A1E', '#2E7359', '#6B4FA0', '#B0417A', '#4A5570', '#8A6D00'];

    #[Locked]
    public $teamId;

    /** Month shown, as Y-m. */
    public $selectedMonth;

    /** Earliest month that can be shown (the team's first), as Y-m. */
    #[Locked]
    public $firstMonth;

    public $selectedLocations = [];

    public $locations = [];

    /** @var array<int, int> group ids expanded in the sidebar; 0 is the ungrouped bucket */
    public $expandedGroups = [];

    public function boot()
    {
        if ($this->teamId) {
            $user = Auth::user();
            if ($user && $user->current_team_id !== $this->teamId) {
                $user->forceFill(['current_team_id' => $this->teamId]);
                $user->unsetRelation('currentTeam');
            }
        }
    }

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-pith-inventory'), 403);

        $this->teamId = $currentTeam->id;
        $this->selectedMonth = now()->format('Y-m');
        $this->firstMonth = ($currentTeam->created_at ? Carbon::parse($currentTeam->created_at) : now())->format('Y-m');

        // Same rule the inventory page uses: active locations, plus deactivated ones
        // that still hold stock — their cft is real and belongs in the history.
        $this->locations = PithLocation::leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
            ->where('pulse_pith_locations.team_id', $this->teamId)
            ->activeOrHoldingStock()
            ->orderBy('pulse_pith_locations.name')
            ->select(
                'pulse_pith_locations.*',
                'pulse_pith_location_groups.name as group_name',
                'pulse_pith_location_groups.display_order as group_display_order'
            )
            ->get()
            ->toArray();

        // Select the first location by default
        if (! empty($this->locations)) {
            $this->selectedLocations = [(string) $this->locations[0]['id']];
        }

        $this->expandedGroups = $this->groupOfSelectedLocations();
    }

    public function previousMonth()
    {
        $previous = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->subMonth()->format('Y-m');

        if ($previous >= $this->firstMonth) {
            $this->selectedMonth = $previous;
        }
    }

    public function nextMonth()
    {
        $next = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->addMonth()->format('Y-m');

        if ($next <= now()->format('Y-m')) {
            $this->selectedMonth = $next;
        }
    }

    /**
     * Group ids holding a currently selected location, so the sidebar opens on
     * whatever is already plotted.
     *
     * @return array<int, int>
     */
    private function groupOfSelectedLocations(): array
    {
        return collect($this->locations)
            ->filter(fn ($location) => in_array((string) $location['id'], $this->selectedLocations, true))
            ->map(fn ($location) => (int) ($location['group_id'] ?? 0))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Fold locations into groups for the sidebar. Ungrouped locations collect
     * under id 0 and always sort last.
     *
     * @return array<int, array<string, mixed>>
     */
    public function locationGroups(): array
    {
        return collect($this->locations)
            ->groupBy(fn ($location) => (int) ($location['group_id'] ?? 0))
            ->map(function ($groupLocations, $groupId) {
                $groupId = (int) $groupId;
                $ids = $groupLocations->pluck('id')->map(fn ($id) => (string) $id)->all();
                $selected = array_intersect($ids, $this->selectedLocations);

                return [
                    'id' => $groupId,
                    'name' => $groupId === 0 ? __('Ungrouped') : ($groupLocations->first()['group_name'] ?? __('Ungrouped')),
                    'is_ungrouped' => $groupId === 0,
                    'display_order' => (int) ($groupLocations->first()['group_display_order'] ?? 0),
                    'locations' => $groupLocations->values()->all(),
                    'locationIds' => $ids,
                    'selectedCount' => count($selected),
                    'allSelected' => count($ids) > 0 && count($selected) === count($ids),
                ];
            })
            ->sortBy([
                fn ($a, $b) => $a['is_ungrouped'] <=> $b['is_ungrouped'],
                fn ($a, $b) => $a['display_order'] <=> $b['display_order'],
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }

    /**
     * Select every location in a group, or clear them all when the group is
     * already fully selected.
     */
    public function toggleGroupSelection($groupId)
    {
        $group = collect($this->locationGroups())->firstWhere('id', (int) $groupId);

        if (! $group) {
            return;
        }

        $this->selectedLocations = $group['allSelected']
            ? array_values(array_diff($this->selectedLocations, $group['locationIds']))
            : array_values(array_unique(array_merge($this->selectedLocations, $group['locationIds'])));
    }

    public function toggleLocation($locationId)
    {
        $locationId = (string) $locationId;

        if (! collect($this->locations)->contains(fn ($location) => (string) $location['id'] === $locationId)) {
            return;
        }

        $this->selectedLocations = in_array($locationId, $this->selectedLocations, true)
            ? array_values(array_diff($this->selectedLocations, [$locationId]))
            : [...$this->selectedLocations, $locationId];
    }

    public function toggleGroupExpanded($groupId)
    {
        $groupId = (int) $groupId;

        $this->expandedGroups = in_array($groupId, $this->expandedGroups, true)
            ? array_values(array_diff($this->expandedGroups, [$groupId]))
            : array_merge($this->expandedGroups, [$groupId]);
    }

    public function clearSelectedLocations()
    {
        $this->selectedLocations = [];
    }

    /**
     * Describe what the compare chart is currently plotting: a whole group by
     * name when the selection maps exactly to one, otherwise a location count.
     */
    public function selectionLabel(): string
    {
        $count = count($this->selectedLocations);

        if ($count === 0) {
            return __('No locations selected');
        }

        $fullGroups = collect($this->locationGroups())->filter(fn ($group) => $group['allSelected']);

        if ($fullGroups->count() === 1 && $fullGroups->first()['selectedCount'] === $count) {
            return $fullGroups->first()['name'];
        }

        return $count.' '.($count === 1 ? __('location') : __('locations'));
    }

    /**
     * Colour of a ticked location's line, keyed by location id.
     *
     * @return array<string, string>
     */
    public function selectionColors(): array
    {
        return collect(array_values($this->selectedLocations))
            ->mapWithKeys(fn ($id, $index) => [(string) $id => self::PALETTE[$index % count(self::PALETTE)]])
            ->all();
    }

    /**
     * Daily series for the selected month: all locations combined, and per ticked location.
     *
     * @return array{month: string, daysInMonth: int, dates: list<string>, aggregate: array<string, list<float>>, locations: list<array<string, mixed>>}
     */
    public function chartData(): array
    {
        $monthStart = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $lastDay = $this->selectedMonth === now()->format('Y-m') ? now()->subDay() : $monthEnd;

        $dates = [];
        for ($cursor = $monthStart->copy(); $cursor->lte($lastDay); $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }

        $series = ['stock' => [], 'in' => [], 'out' => [], 'water' => []];
        $locationIds = collect($this->locations)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $perLocation = [];

        if ($dates !== [] && $locationIds !== []) {
            $from = $dates[0];
            $to = end($dates);

            $stock = PithInventorySummary::where('team_id', $this->teamId)
                ->whereIn('location_id', $locationIds)
                ->whereBetween('summary_date', [$from, $to])
                ->get(['location_id', 'summary_date', 'in_stock'])
                ->groupBy('location_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [Carbon::parse($row->summary_date)->toDateString() => (float) $row->in_stock]));

            // A day with no snapshot row hasn't been written yet, not emptied — an emptied
            // location gets a zero row. So each location carries its last known figure,
            // starting from its latest row before the month.
            $stockBeforeMonth = PithInventorySummary::from('pulse_pith_inventory_summary as s')
                ->where('s.team_id', $this->teamId)
                ->whereIn('s.location_id', $locationIds)
                ->whereRaw('s.summary_date = (SELECT MAX(p.summary_date) FROM pulse_pith_inventory_summary p WHERE p.location_id = s.location_id AND p.team_id = s.team_id AND p.summary_date < ?)', [$from])
                ->pluck('s.in_stock', 's.location_id');

            $movements = DB::table('pulse_pith_inventory')
                ->whereIn('pith_location_id', $locationIds)
                ->whereBetween('date', [$from, $to])
                ->selectRaw('pith_location_id, DATE(date) as day, COALESCE(SUM(cft_offloaded), 0) as inward, COALESCE(SUM(taken_cft_calculated), 0) as outward')
                ->groupBy('pith_location_id', DB::raw('DATE(date)'))
                ->get()
                ->groupBy('pith_location_id')
                ->map(fn ($rows) => $rows->keyBy('day'));

            $water = DB::table('pulse_pith_waterings')
                ->whereIn('pith_location_id', $locationIds)
                ->whereBetween('date', [$from, $to])
                ->selectRaw('pith_location_id, date as day, SUM(litres) as litres')
                ->groupBy('pith_location_id', 'date')
                ->get()
                ->groupBy('pith_location_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [Carbon::parse($row->day)->toDateString() => (int) $row->litres]));

            foreach ($locationIds as $id) {
                $carriedStock = (float) ($stockBeforeMonth[$id] ?? 0);

                foreach ($dates as $date) {
                    $carriedStock = (float) ($stock[$id][$date] ?? $carriedStock);
                    $perLocation[$id]['stock'][] = round($carriedStock, 2);
                    $perLocation[$id]['in'][] = round((float) ($movements[$id][$date]->inward ?? 0), 2);
                    $perLocation[$id]['out'][] = round((float) ($movements[$id][$date]->outward ?? 0), 2);
                    $perLocation[$id]['water'][] = (int) ($water[$id][$date] ?? 0);
                }
            }

            foreach (array_keys($series) as $metric) {
                foreach (array_keys($dates) as $index) {
                    $series[$metric][] = round(collect($perLocation)->sum(fn ($location) => $location[$metric][$index]), 2);
                }
            }
        }

        $names = collect($this->locations)->mapWithKeys(fn ($location) => [(string) $location['id'] => $location['name']]);
        $colors = $this->selectionColors();

        $selected = collect($this->selectedLocations)
            ->filter(fn ($id) => $names->has((string) $id))
            ->map(fn ($id) => [
                'id' => (int) $id,
                'name' => $names[(string) $id],
                'color' => $colors[(string) $id],
            ] + ($perLocation[(int) $id] ?? ['stock' => [], 'in' => [], 'out' => [], 'water' => []]))
            ->values()
            ->all();

        return [
            'month' => $monthStart->format('M Y'),
            'monthShort' => $monthStart->format('M'),
            'year' => $monthStart->format('Y'),
            'daysInMonth' => $monthStart->daysInMonth,
            'dates' => $dates,
            'aggregate' => $series,
            'locations' => $selected,
        ];
    }

    public function render()
    {
        return view('pulse::livewire.pith.pith-history', [
            'chart' => $this->chartData(),
            'locationGroups' => $this->locationGroups(),
            'selectionColors' => $this->selectionColors(),
            'canGoBack' => $this->selectedMonth > $this->firstMonth,
            'canGoForward' => $this->selectedMonth < now()->format('Y-m'),
        ]);
    }
}
