<?php

namespace App\Livewire\Pulse\Pith;

use App\Models\Pulse\HusksDailyStock;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Husk Inventory: the latest day's husk stock, a month of daily rows as a list or chart, and
 * the Update Stock form for husks received plus an optional correction.
 *
 * Each day's row is created by husks:daily-stock the morning after, and reconcile_husks_stock
 * recomputes opening/closing from a date onwards: consumed is that day's pith production times
 * HUSKS_PER_CFT, and closing already nets in the correction. A day with no row carries the last
 * known closing stock in the chart rather than reading as empty.
 */
class HuskInventory extends Component
{
    /** Set once in mount(); every query on this page is scoped to it, so the client can't change it. */
    #[Locked]
    public $teamId;

    /** Month shown, as Y-m. */
    public $selectedMonth;

    /** Earliest month that can be shown (the team's first), as Y-m. */
    #[Locked]
    public $firstMonth;

    // Update Stock form, open while $stockId is set
    #[Locked]
    public $stockId = null;

    public $husksReceived = null;

    /** 'add' or 'deduct'. */
    public $correctionType = 'add';

    public $correctionAmount = null;

    public $correctionComment = null;

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }
        abort_unless(Gate::allows('view-husk-inventory'), 403);

        $this->teamId = $currentTeam->id;
        $this->selectedMonth = now()->format('Y-m');
        $this->firstMonth = ($currentTeam->created_at ? Carbon::parse($currentTeam->created_at) : now())->format('Y-m');
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
     * Jump straight to a month from the month picker, as Y-m.
     */
    public function selectMonth(string $month)
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) && $month >= $this->firstMonth && $month <= now()->format('Y-m')) {
            $this->selectedMonth = $month;
        }
    }

    /**
     * Opens the form on yesterday, creating its row first when husks:daily-stock hasn't yet,
     * which makes it the latest record.
     */
    public function openUpdateModal()
    {
        if (! Gate::allows('husk_inventory.update')) {
            return;
        }

        $yesterday = Carbon::yesterday()->toDateString();
        $record = HusksDailyStock::where('team_id', $this->teamId)->where('date', $yesterday)->first();

        if (! $record) {
            $openingStock = HusksDailyStock::where('team_id', $this->teamId)
                ->latest('date')
                ->value('closing_stock') ?? 0;

            $record = HusksDailyStock::create([
                'team_id' => $this->teamId,
                'date' => $yesterday,
                'opening_stock' => $openingStock,
                'closing_stock' => $openingStock,
                'cft' => 0,
                'husks_received' => null,
                'husks_correction' => 0,
                'correction_comment' => null,
            ]);

            DB::statement('CALL reconcile_husks_stock(?, ?)', [$this->teamId, $yesterday]);
            $record->refresh();
        }

        $this->fillForm($record);
    }

    /**
     * Edit the latest record from its row or a pinned chart day, whatever date it falls on.
     * Every record before it is read-only.
     */
    public function editLatest(string $date)
    {
        if (! Gate::allows('husk_inventory.update')) {
            return;
        }

        $latest = $this->latestRecord();

        if ($latest && $latest->date->toDateString() === $date) {
            $this->fillForm($latest);
        }
    }

    public function closeUpdateModal()
    {
        $this->resetForm();
    }

    public function updateStock()
    {
        if (! Gate::allows('husk_inventory.update')) {
            $this->toast(__('You are not authorized to update husk stock.'), true);

            return;
        }

        $this->validate([
            'husksReceived' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'correctionType' => ['required', 'in:add,deduct'],
            'correctionAmount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'correctionComment' => ['nullable', 'string', 'max:150'],
        ], [
            'husksReceived.required' => __('Enter the husks received, or 0 if none came in.'),
        ]);

        $record = $this->formRecord();

        // Only the latest record can change. A form left open while husks:daily-stock adds
        // the next day's row is editing a record that is no longer the latest.
        if (! $record || $record->id !== $this->latestRecord()?->id) {
            $this->resetForm();
            $this->toast(__('That day can no longer be changed.'), true);

            return;
        }

        $amount = (float) ($this->correctionAmount ?? 0);
        $signedCorrection = $amount > 0 ? ($this->correctionType === 'deduct' ? -$amount : $amount) : 0;

        $record->update([
            'user_id' => Auth::id(),
            'husks_received' => $this->husksReceived,
            'husks_correction' => $signedCorrection,
            'correction_comment' => filled($this->correctionComment) ? $this->correctionComment : null,
        ]);

        DB::statement('CALL reconcile_husks_stock(?, ?)', [$this->teamId, $record->date->toDateString()]);

        $this->resetForm();
        $this->toast(__('Husk stock updated for :date', ['date' => $record->date->format(config('app.date_format'))]));
    }

    private function fillForm(HusksDailyStock $record): void
    {
        $correction = (float) ($record->husks_correction ?? 0);

        $this->resetErrorBag();
        $this->stockId = $record->id;
        $this->husksReceived = $record->husks_received !== null ? (float) $record->husks_received : null;
        $this->correctionType = $correction < 0 ? 'deduct' : 'add';
        $this->correctionAmount = $correction != 0 ? abs($correction) : null;
        $this->correctionComment = $record->correction_comment;
    }

    private function resetForm(): void
    {
        $this->stockId = null;
        $this->husksReceived = null;
        $this->correctionType = 'add';
        $this->correctionAmount = null;
        $this->correctionComment = null;
        $this->resetErrorBag();
    }

    /**
     * The team's most recent record. A row dated today is still forming and never listed,
     * so it doesn't count.
     */
    private function latestRecord(): ?HusksDailyStock
    {
        return HusksDailyStock::where('team_id', $this->teamId)
            ->where('date', '<', now()->toDateString())
            ->latest('date')
            ->first();
    }

    private function formRecord(): ?HusksDailyStock
    {
        return $this->stockId
            ? HusksDailyStock::where('team_id', $this->teamId)->where('id', $this->stockId)->first()
            : null;
    }

    private function toast(string $message, bool $isError = false): void
    {
        $this->dispatch('pi-toast', message: $message, error: $isError);
    }

    /**
     * Husks used on a row's day: opening + received + correction − closing, which the
     * procedure sets from pith production × HUSKS_PER_CFT.
     */
    private static function consumedOn(HusksDailyStock $row): float
    {
        return round((float) $row->opening_stock + (float) ($row->husks_received ?? 0) + (float) ($row->husks_correction ?? 0) - (float) $row->closing_stock, 2);
    }

    /**
     * Yesterday's row, or the latest one when yesterday's hasn't been created.
     *
     * @return array{date: ?string, is_yesterday: bool, pending: bool, opening: float, received: ?float, consumed: float, correction: float, comment: ?string, stock: float, last_received: ?array{date: string, qty: float}}
     */
    private function summary(): array
    {
        $yesterday = Carbon::yesterday()->toDateString();

        $record = HusksDailyStock::where('team_id', $this->teamId)->where('date', $yesterday)->first()
            ?? HusksDailyStock::where('team_id', $this->teamId)->latest('date')->first();

        $lastReceived = HusksDailyStock::where('team_id', $this->teamId)
            ->where('husks_received', '>', 0)
            ->latest('date')
            ->first(['date', 'husks_received']);

        return [
            'date' => $record?->date->toDateString(),
            'is_yesterday' => $record?->date->toDateString() === $yesterday,
            'pending' => ! $record || $record->date->toDateString() !== $yesterday || $record->husks_received === null,
            'opening' => (float) ($record->opening_stock ?? 0),
            'received' => $record?->husks_received !== null ? (float) $record->husks_received : null,
            'consumed' => $record ? self::consumedOn($record) : 0.0,
            'correction' => (float) ($record->husks_correction ?? 0),
            'comment' => $record?->correction_comment,
            'stock' => (float) ($record->closing_stock ?? 0),
            'last_received' => $lastReceived ? ['date' => $lastReceived->date->toDateString(), 'qty' => (float) $lastReceived->husks_received] : null,
        ];
    }

    /**
     * The selected month's days up to yesterday — today's row is still forming — as list
     * rows (newest first) and chart series (oldest first).
     *
     * @return array{month: string, daysInMonth: int, dates: list<string>, days: Collection<int, array<string, mixed>>, totals: array<string, float>, series: array<string, list<float|null>>}
     */
    private function monthData(): array
    {
        $monthStart = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->startOfDay();
        $lastDay = $this->selectedMonth === now()->format('Y-m') ? Carbon::yesterday() : $monthStart->copy()->endOfMonth();

        $dates = [];
        for ($cursor = $monthStart->copy(); $cursor->lte($lastDay); $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }

        $rows = $dates === [] ? collect() : HusksDailyStock::where('team_id', $this->teamId)
            ->whereBetween('date', [$dates[0], end($dates)])
            ->get()
            ->keyBy(fn (HusksDailyStock $row) => $row->date->toDateString());

        $carried = $dates === [] ? 0.0 : (float) (HusksDailyStock::where('team_id', $this->teamId)
            ->where('date', '<', $dates[0])
            ->latest('date')
            ->value('closing_stock') ?? 0);

        $yesterday = Carbon::yesterday()->toDateString();
        $series = ['stock' => [], 'received' => [], 'consumed' => [], 'correction' => []];
        $days = [];

        foreach ($dates as $date) {
            $row = $rows->get($date);

            if ($row) {
                $carried = (float) $row->closing_stock;
            }

            $day = [
                'date' => $date,
                'has_row' => (bool) $row,
                'is_yesterday' => $date === $yesterday,
                'pending' => $row && $row->husks_received === null,
                'opening' => $row ? (float) $row->opening_stock : null,
                'received' => $row ? (float) ($row->husks_received ?? 0) : null,
                'consumed' => $row ? self::consumedOn($row) : null,
                'correction' => $row ? (float) ($row->husks_correction ?? 0) : null,
                'comment' => $row?->correction_comment,
                'stock' => $row ? (float) $row->closing_stock : null,
            ];
            $days[] = $day;

            $series['stock'][] = round($carried, 2);
            $series['received'][] = round($day['received'] ?? 0, 2);
            $series['consumed'][] = round($day['consumed'] ?? 0, 2);
            $series['correction'][] = round($day['correction'] ?? 0, 2);
        }

        $recorded = collect($days)->where('has_row', true);

        return [
            'month' => $monthStart->format('M Y'),
            'daysInMonth' => $monthStart->daysInMonth,
            'dates' => $dates,
            'days' => collect($days)->reverse()->values(),
            'totals' => [
                'received' => (float) $recorded->sum('received'),
                'consumed' => (float) $recorded->sum('consumed'),
                'correction' => (float) $recorded->sum('correction'),
                'stock' => $recorded->isNotEmpty() ? (float) $recorded->last()['stock'] : $carried,
            ],
            'series' => $series,
        ];
    }

    public function render()
    {
        $month = $this->monthData();
        $record = $this->formRecord();

        $lastEntry = null;
        if ($record && ! ((float) ($record->husks_received ?? 0) > 0)) {
            $lastEntry = HusksDailyStock::where('team_id', $this->teamId)
                ->where('date', '<', $record->date->toDateString())
                ->where('husks_received', '>', 0)
                ->latest('date')
                ->first(['date', 'husks_received']);
        }

        $oldestFirst = $month['days']->reverse()->values();
        $latestDate = $this->latestRecord()?->date->toDateString();

        return view('pulse::livewire.pith.husk-inventory', [
            'summary' => $this->summary(),
            'month' => $month,
            'chart' => [
                'labels' => array_map(fn (string $date) => Carbon::parse($date)->format(config('app.date_format')), $month['dates']),
                'daysInMonth' => $month['daysInMonth'],
                'dates' => $month['dates'],
                'series' => $month['series'],
                'recorded' => $oldestFirst->pluck('has_row')->all(),
                'editable' => $oldestFirst->map(fn (array $day) => $day['date'] === $latestDate)->all(),
                'comments' => $oldestFirst->pluck('comment')->all(),
            ],
            'latestDate' => $latestDate,
            'canUpdate' => Gate::allows('husk_inventory.update'),
            'canReport' => Gate::allows('husk_inventory.inventory_report'),
            'canGoBack' => $this->selectedMonth > $this->firstMonth,
            'canGoForward' => $this->selectedMonth < now()->format('Y-m'),
            'formRecord' => $record,
            'formConsumed' => $record ? self::consumedOn($record) : 0.0,
            'formLastEntry' => $lastEntry,
        ]);
    }
}
