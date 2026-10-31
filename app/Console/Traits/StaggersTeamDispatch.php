<?php

namespace App\Console\Traits;

use Carbon\Carbon;

/**
 * Spreads a command's per-team dispatches over a bounded window instead of firing them
 * together.
 *
 * Each team's delay is derived from its shift anchor, so every team sharing an anchor
 * became due in the same second and their jobs hit the same summary tables at once.
 * That concurrency is what deadlocked the nightly pith runs against each other.
 *
 * The offset wraps once the window is full rather than growing with the team count, so
 * no team is ever pushed further out than `app.team_dispatch_window_mins` past its
 * buffer — a factory's data must not land hours after its shift ends. Beyond that point
 * teams share slots and run concurrently again, which the summary-table indexes make
 * safe; jobs finish in single-digit seconds, so a slot is long clear before the next.
 */
trait StaggersTeamDispatch
{
    /**
     * When a team's job should run: its shift anchor, plus the module's buffer, plus a
     * per-team offset within the stagger window.
     *
     * @param  string  $startTime  The team's shift anchor, as `H:i:s`.
     * @param  int  $bufferMinutes  The module's buffer after the anchor.
     * @param  int  $position  The team's zero-based position in the dispatch loop.
     */
    protected function delayForTeam(string $startTime, int $bufferMinutes, int $position): Carbon
    {
        return Carbon::now()
            ->setTimeFromTimeString($startTime)
            ->addMinutes($bufferMinutes)
            ->addSeconds($this->staggerOffsetFor($position));
    }

    /**
     * Seconds past the buffer for a team, wrapped to keep every team inside the window.
     */
    protected function staggerOffsetFor(int $position): int
    {
        $gap = max(1, (int) config('app.team_dispatch_stagger_secs'));
        $window = max(0, (int) config('app.team_dispatch_window_mins')) * 60;
        $slots = max(1, intdiv($window, $gap));

        return ($position % $slots) * $gap;
    }
}
