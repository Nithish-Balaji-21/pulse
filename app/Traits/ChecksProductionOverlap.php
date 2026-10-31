<?php

namespace App\Traits;

trait ChecksProductionOverlap
{
    /**
     * Whether an existing (non-excluded) production row for this team/device
     * overlaps the given start/end window. Uses a standard start-inclusive,
     * end-exclusive interval overlap test (consistent with how shift windows
     * are treated elsewhere in the app) — two rows overlap iff each one's
     * start is before the other's end. Scoped by device_id via Eloquent's
     * null-safe two-argument `where()`, so teams with more than one device,
     * or software-only teams with a null device_id, aren't compared against
     * unrelated devices.
     *
     * @param  class-string  $modelClass
     */
    protected function productionOverlaps(string $modelClass, $teamId, $deviceId, $startTime, $endTime, $ignoreId = null): bool
    {
        return $modelClass::where('team_id', $teamId)
            ->where('device_id', $deviceId)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
