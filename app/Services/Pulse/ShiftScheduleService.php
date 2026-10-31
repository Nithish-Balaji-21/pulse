<?php

namespace App\Services\Pulse;

use App\Models\Pulse\Shift;
use App\Models\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftScheduleService
{
    /**
     * Validate a draft schedule against the team's anchor.
     *
     * Each shift is ['id' => ?int, 'name' => string, 'start_time' => 'HH:MM', 'end_time' => 'HH:MM'].
     * Returns error messages keyed by the shift's array index ('schedule' for schedule-wide errors).
     *
     * @param  array<int, array{id?: int|null, name?: string|null, start_time?: string|null, end_time?: string|null}>  $shifts
     * @return array<int|string, string>
     */
    public function validateSchedule(array $shifts, string $anchor, ?int $teamId = null): array
    {
        $errors = [];

        if (! $this->isValidClockTime($anchor)) {
            return ['schedule' => 'The shift day start must be a valid HH:MM time.'];
        }

        if (count($shifts) === 0) {
            return ['schedule' => 'At least one shift is required.'];
        }
        if (count($shifts) > 3) {
            return ['schedule' => 'Max 3 shifts only.'];
        }

        $existingIds = $teamId
            ? Shift::where('team_id', $teamId)->pluck('id')->all()
            : null;

        $windows = [];
        $names = [];

        foreach (array_values($shifts) as $index => $shift) {
            $name = trim((string) ($shift['name'] ?? ''));
            if ($name === '') {
                $errors[$index] = 'The shift name is required.';

                continue;
            }
            if (mb_strlen($name) > 50) {
                $errors[$index] = 'The shift name may not be greater than 50 characters.';

                continue;
            }
            if (in_array(mb_strtolower($name), $names, true)) {
                $errors[$index] = 'The shift name must be unique.';

                continue;
            }
            $names[] = mb_strtolower($name);

            if (! $this->isValidClockTime($shift['start_time'] ?? null) || ! $this->isValidClockTime($shift['end_time'] ?? null)) {
                $errors[$index] = 'Start and end times are required (HH:MM).';

                continue;
            }

            if (! empty($shift['id']) && $existingIds !== null && ! in_array((int) $shift['id'], $existingIds, true)) {
                $errors[$index] = 'Unknown shift for this factory.';

                continue;
            }

            $startOffset = Shift::clockToOffset($this->normalizeTime($shift['start_time']), $anchor);
            $endOffset = Shift::clockToOffset($this->normalizeTime($shift['end_time']), $anchor);
            if ($endOffset === 0) {
                $endOffset = Shift::MINUTES_PER_DAY;
            }

            if ($startOffset >= $endOffset) {
                $anchorLabel = substr($anchor, 0, 5);
                $errors[$index] = "The shift must start before it ends and may not cross the {$anchorLabel} day boundary.";

                continue;
            }

            $windows[] = ['index' => $index, 'name' => $name, 'start' => $startOffset, 'end' => $endOffset];
        }

        usort($windows, fn ($a, $b) => $a['start'] <=> $b['start']);

        foreach ($windows as $i => $window) {
            if ($i === 0) {
                continue;
            }
            $previous = $windows[$i - 1];
            if ($previous['end'] > $window['start']) {
                $errors[$window['index']] = "This shift overlaps with \"{$previous['name']}\".";
            }
        }

        return $errors;
    }

    /**
     * Persist a validated schedule atomically. Shifts missing from the payload are
     * deactivated, never deleted (production/summary rows reference shift ids).
     *
     * Pass $anchor to move the shift-day boundary in the same transaction. The anchor and
     * the schedule constrain each other, so a change that moves both (say, a 07:00-anchored
     * round-the-clock shift becoming an 08:00-anchored day/night pair) has no valid
     * intermediate state and has to be written as one step.
     *
     * @param  array<int, array{id?: int|null, name: string, start_time: string, end_time: string}>  $shifts
     */
    public function saveSchedule(int $teamId, array $shifts, int $userId, ?string $anchor = null): Collection
    {
        return DB::transaction(function () use ($teamId, $shifts, $userId, $anchor) {
            if ($anchor !== null) {
                $this->persistAnchor($teamId, $this->normalizeTime($anchor), $userId);
            }

            $existing = Shift::where('team_id', $teamId)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $keptIds = [];

            foreach (array_values($shifts) as $data) {
                $attributes = [
                    'name' => trim($data['name']),
                    'start_time' => $this->normalizeTime($data['start_time']),
                    'end_time' => $this->normalizeTime($data['end_time']),
                    'is_active' => 1,
                    'updated_by' => $userId,
                ];

                $shift = null;
                if (! empty($data['id'])) {
                    $shift = $existing->get((int) $data['id']);
                }
                if (! $shift) {
                    // Reuse a deactivated row carrying the same name — (team_id, name) is unique
                    $shift = $existing->first(fn (Shift $candidate) => mb_strtolower($candidate->name) === mb_strtolower($attributes['name'])
                        && ! in_array($candidate->id, $keptIds, true));
                }

                if ($shift) {
                    $shift->update($attributes);
                } else {
                    $shift = Shift::create($attributes + [
                        'team_id' => $teamId,
                        'created_by' => $userId,
                    ]);
                }

                $keptIds[] = $shift->id;
            }

            Shift::where('team_id', $teamId)
                ->whereNotIn('id', $keptIds)
                ->where('is_active', 1)
                ->update(['is_active' => 0, 'updated_by' => $userId]);

            return Shift::activeForTeam($teamId)
                ->orderedByOffset(Shift::anchorForTeam($teamId))
                ->get();
        });
    }

    /**
     * Change the team's shift-day anchor. Rejects (422) if any active shift would
     * cross the new day boundary — the anchor must never invalidate the schedule.
     */
    public function updateAnchor(int $teamId, string $newAnchor, int $userId): void
    {
        $newAnchor = $this->normalizeTime($newAnchor);

        DB::transaction(function () use ($teamId, $newAnchor, $userId) {
            $offenders = Shift::activeForTeam($teamId)
                ->lockForUpdate()
                ->get()
                ->filter(function (Shift $shift) use ($newAnchor) {
                    [$startOffset, $endOffset] = $shift->offsetsFor($newAnchor);

                    return $startOffset >= $endOffset;
                });

            if ($offenders->isNotEmpty()) {
                $names = $offenders->map(fn (Shift $shift) => "{$shift->name} (#{$shift->id})")->implode(', ');

                throw ValidationException::withMessages([
                    'shift_day_anchor' => "These shifts would cross the new day boundary: {$names}. Adjust the shifts first.",
                ]);
            }

            $this->persistAnchor($teamId, $newAnchor, $userId);
        });
    }

    /**
     * Write the team's anchor setting. Callers own the validation — {@see updateAnchor()}
     * guards it against the saved schedule, {@see saveSchedule()} against the incoming one.
     */
    protected function persistAnchor(int $teamId, string $anchor, int $userId): void
    {
        $setting = Settings::where('team_id', $teamId)
            ->where('key', Shift::ANCHOR_SETTING_KEY)
            ->first();

        if ($setting) {
            $setting->update(['value' => $anchor, 'updated_by' => $userId]);
        } else {
            DB::table('pulse_settings')->insert([
                'team_id' => $teamId,
                'module' => 'shifts',
                'key' => Shift::ANCHOR_SETTING_KEY,
                'text' => 'Shift day anchor (start of the 24-hour production day)',
                'value' => $anchor,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Shift::forgetAnchorCache($teamId);
    }

    /**
     * Total uncovered minutes in the shift day for the team's active shifts.
     */
    public function gapMinutes(int $teamId): int
    {
        $shifts = Shift::activeForTeam($teamId)
            ->get()
            ->map(fn (Shift $shift) => [
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ])
            ->all();

        if ($shifts === []) {
            return 0;
        }

        return $this->gapMinutesForDraft($shifts, Shift::anchorForTeam($teamId));
    }

    /**
     * Total uncovered minutes in the shift day for a draft schedule. Shifts with
     * missing or window-invalid times are ignored.
     *
     * @param  array<int, array{start_time?: string|null, end_time?: string|null}>  $shifts
     */
    public function gapMinutesForDraft(array $shifts, string $anchor): int
    {
        $offsets = collect($shifts)
            ->map(function (array $shift) use ($anchor) {
                if (! $this->isValidClockTime($shift['start_time'] ?? null) || ! $this->isValidClockTime($shift['end_time'] ?? null)) {
                    return null;
                }

                $start = Shift::clockToOffset($this->normalizeTime($shift['start_time']), $anchor);
                $end = Shift::clockToOffset($this->normalizeTime($shift['end_time']), $anchor);
                if ($end === 0) {
                    $end = Shift::MINUTES_PER_DAY;
                }

                return $start < $end ? [$start, $end] : null;
            })
            ->filter()
            ->sortBy(fn (array $window) => $window[0])
            ->values();

        if ($offsets->isEmpty()) {
            return Shift::MINUTES_PER_DAY;
        }

        $gap = $offsets->first()[0];
        $previousEnd = null;

        foreach ($offsets as [$start, $end]) {
            if ($previousEnd !== null) {
                $gap += max(0, $start - $previousEnd);
            }
            $previousEnd = max($previousEnd ?? 0, $end);
        }

        return $gap + max(0, Shift::MINUTES_PER_DAY - $previousEnd);
    }

    public function normalizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }

        return $time;
    }

    protected function isValidClockTime(?string $time): bool
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) === 1;
    }
}
