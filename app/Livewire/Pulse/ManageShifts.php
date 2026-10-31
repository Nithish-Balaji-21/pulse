<?php

namespace App\Livewire\Pulse;

use App\Models\Pulse\Shift;
use App\Services\Pulse\ShiftScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageShifts extends Component
{
    public $teamId;

    /** Saved team anchor as HH:MM:SS */
    public $anchor = Shift::DEFAULT_ANCHOR;

    /** Anchor being edited, as HH:MM. Part of the draft — saving the schedule commits it. */
    public $anchorInput;

    /** Draft schedule: [['id' => ?int, 'name' => string, 'start_time' => 'HH:MM', 'end_time' => 'HH:MM'], ...] */
    public $draft = [];

    /** Validation messages keyed by draft index */
    public $draftErrors = [];

    /** Schedule-wide validation message */
    public $scheduleError = null;

    public $successMessage = null;

    protected ShiftScheduleService $scheduleService;

    public function boot(ShiftScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    public function mount()
    {
        abort_unless(Gate::allows('shift_settings.view'), 403);

        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->loadSchedule();
    }

    public function loadSchedule()
    {
        Shift::forgetAnchorCache($this->teamId);
        $this->anchor = Shift::anchorForTeam($this->teamId);
        $this->anchorInput = substr($this->anchor, 0, 5);

        $this->draft = Shift::activeForTeam($this->teamId)
            ->orderedByOffset($this->anchor)
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'name' => $shift->name,
                'start_time' => substr($shift->start_time, 0, 5),
                'end_time' => substr($shift->end_time, 0, 5),
            ])
            ->values()
            ->all();

        $this->validateDraft();
    }

    public function updated($property)
    {
        if (str_starts_with($property, 'draft') || $property === 'anchorInput') {
            $this->successMessage = null;
            $this->validateDraft();
        }
    }

    public function validateDraft()
    {
        $errors = $this->draft === []
            ? []
            : $this->scheduleService->validateSchedule($this->draft, $this->draftAnchor(), $this->teamId);

        $this->scheduleError = $errors['schedule'] ?? null;
        unset($errors['schedule']);
        $this->draftErrors = $errors;
    }

    /**
     * Replace the draft with a preset: 1 shift of 10h, 2 halves of 12h, or 3 thirds of 8h,
     * all measured from the day start being edited. Existing ids are reused by position so saving
     * updates rows instead of deactivating them.
     */
    public function applyPreset(int $shiftCount)
    {
        $durations = [
            1 => [600],
            2 => [720, 720],
            3 => [480, 480, 480],
        ][$shiftCount] ?? null;

        if (! $durations) {
            return;
        }

        $draft = [];
        $offset = 0;
        foreach ($durations as $index => $minutes) {
            $draft[] = [
                'id' => $this->draft[$index]['id'] ?? null,
                'name' => 'Shift '.($index + 1),
                'start_time' => substr(Shift::offsetToClock($offset, $this->draftAnchor()), 0, 5),
                'end_time' => substr(Shift::offsetToClock($offset + $minutes, $this->draftAnchor()), 0, 5),
            ];
            $offset += $minutes;
        }

        $this->draft = $draft;
        $this->successMessage = null;
        $this->validateDraft();
    }

    public function addShift()
    {
        if (count($this->draft) >= 3) {
            return;
        }

        // New shift starts where the current schedule ends
        $maxEndOffset = 0;
        foreach ($this->draft as $shift) {
            if ($this->isClockTime($shift['end_time'] ?? null)) {
                $endOffset = Shift::clockToOffset($shift['end_time'].':00', $this->draftAnchor());
                $maxEndOffset = max($maxEndOffset, $endOffset === 0 ? Shift::MINUTES_PER_DAY : $endOffset);
            }
        }

        $startOffset = min($maxEndOffset, Shift::MINUTES_PER_DAY - 1);
        $endOffset = min($startOffset + 480, Shift::MINUTES_PER_DAY);

        $this->draft[] = [
            'id' => null,
            'name' => 'Shift '.(count($this->draft) + 1),
            'start_time' => substr(Shift::offsetToClock($startOffset, $this->draftAnchor()), 0, 5),
            'end_time' => substr(Shift::offsetToClock($endOffset, $this->draftAnchor()), 0, 5),
        ];

        $this->successMessage = null;
        $this->validateDraft();
    }

    public function removeShift(int $index)
    {
        unset($this->draft[$index]);
        $this->draft = array_values($this->draft);
        $this->successMessage = null;
        $this->validateDraft();
    }

    public function save()
    {
        if (! Gate::allows('shift_settings.edit')) {
            $this->scheduleError = 'You are not authorized to manage shifts.';

            return;
        }

        $this->validateDraft();
        if ($this->draft === []) {
            $this->scheduleError = 'At least one shift is required.';

            return;
        }
        if ($this->scheduleError || $this->draftErrors !== []) {
            return;
        }

        // Anchor and shifts go together: a schedule that only becomes legal under the new
        // day start has no valid intermediate state, so neither half can be saved alone.
        $anchorChanged = $this->draftAnchor() !== $this->anchor;

        $this->scheduleService->saveSchedule($this->teamId, $this->draft, Auth::id(), $this->draftAnchor());
        $this->loadSchedule();
        $this->successMessage = $anchorChanged
            ? 'Shift schedule and day start saved successfully'
            : 'Shift schedule saved successfully';
    }

    /**
     * The anchor the draft is measured against: the one being edited while it is a valid
     * clock time, otherwise the saved one so the timeline still renders.
     */
    public function draftAnchor(): string
    {
        return $this->isClockTime($this->anchorInput)
            ? $this->anchorInput.':00'
            : $this->anchor;
    }

    public function render()
    {
        return view('pulse::livewire.manage-shifts', [
            'timeline' => $this->timelineBlocks(),
            'ticks' => $this->tickLabels(),
            'coverage' => $this->coveragePercent(),
            'isValid' => $this->scheduleError === null && $this->draftErrors === [] && $this->draft !== [],
            'anchorLabel' => $this->formatClock($this->draftAnchor()),
            'anchorChanged' => $this->draftAnchor() !== $this->anchor,
            'canManage' => Gate::allows('shift_settings.edit'),
        ]);
    }

    /**
     * Timeline rendering data: one block per draft shift positioned by its offsets.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function timelineBlocks(): array
    {
        $colors = array_values(array_filter((array) config('colors.shift', [])));
        $fallbacks = ['#2563eb', '#16a34a', '#d97706'];

        $blocks = [];
        foreach ($this->draft as $index => $shift) {
            if (! $this->isClockTime($shift['start_time'] ?? null) || ! $this->isClockTime($shift['end_time'] ?? null)) {
                continue;
            }

            $startOffset = Shift::clockToOffset($shift['start_time'].':00', $this->draftAnchor());
            $endOffset = Shift::clockToOffset($shift['end_time'].':00', $this->draftAnchor());
            if ($endOffset === 0) {
                $endOffset = Shift::MINUTES_PER_DAY;
            }

            $invalid = isset($this->draftErrors[$index]) || $startOffset >= $endOffset;
            $width = $startOffset < $endOffset
                ? $endOffset - $startOffset
                : Shift::MINUTES_PER_DAY - $startOffset;

            $blocks[] = [
                'index' => $index,
                'name' => $shift['name'] ?: 'Shift '.($index + 1),
                'leftPct' => round($startOffset / Shift::MINUTES_PER_DAY * 100, 2),
                'widthPct' => round(max($width, 15) / Shift::MINUTES_PER_DAY * 100, 2),
                'color' => $colors[$index] ?? $fallbacks[$index % 3],
                'invalid' => $invalid,
                'label' => $this->formatClock($shift['start_time']).' – '.$this->formatClock($shift['end_time']),
            ];
        }

        return $blocks;
    }

    /**
     * Axis tick labels every 4 hours, computed from the anchor.
     *
     * @return array<int, array{posPct: float, label: string}>
     */
    protected function tickLabels(): array
    {
        $ticks = [];
        for ($offset = 0; $offset <= Shift::MINUTES_PER_DAY; $offset += 240) {
            $ticks[] = [
                'posPct' => round($offset / Shift::MINUTES_PER_DAY * 100, 2),
                'label' => Carbon::parse(Shift::offsetToClock($offset, $this->draftAnchor()))->format('g a'),
            ];
        }

        return $ticks;
    }

    protected function coveragePercent(): int
    {
        if ($this->draft === []) {
            return 0;
        }

        return (int) round((Shift::MINUTES_PER_DAY - $this->scheduleService->gapMinutesForDraft($this->draft, $this->draftAnchor())) / Shift::MINUTES_PER_DAY * 100);
    }

    public function startsNextDayBadge(?string $time): bool
    {
        return $this->isClockTime($time) && ($time.':00') < $this->draftAnchor();
    }

    public function endsNextDayBadge(?string $time): bool
    {
        return $this->isClockTime($time) && ($time.':00') <= $this->draftAnchor();
    }

    protected function formatClock(string $time): string
    {
        return Carbon::parse($time)->format(config('app.time_format', 'h:i a'));
    }

    protected function isClockTime($time): bool
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
