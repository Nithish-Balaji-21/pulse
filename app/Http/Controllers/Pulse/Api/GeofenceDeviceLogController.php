<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pulse\Api\Concerns\AuthorizesGeofences;
use App\Models\Pulse\GeofenceDeviceLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accepts full-trail geofence / device-health logs from every staff phone.
 * Stores per-user rows so owners can see all employees in one place.
 * Does not send push notifications (owners use GET geofence-logs / in-app screen).
 */
class GeofenceDeviceLogController extends Controller
{
    use AuthorizesGeofences;

    private const TIMEZONE = 'Asia/Kolkata';

    /**
     * Staff phone → server (multi-user automated logging).
     */
    public function store(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $user = $request->user();

        $validated = $request->validate([
            'step' => ['required', 'string', 'max:40'],
            'category' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:120'],
            'geofence_id' => ['nullable', 'string', 'max:40'],
            'event_type' => ['nullable', 'string', 'max:10'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'battery_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'battery_optimization_ignored' => ['nullable', 'boolean'],
            'gps_enabled' => ['nullable', 'boolean'],
            'location_permission' => ['nullable', 'string', 'max:40'],
            'background_location_permission' => ['nullable', 'string', 'max:40'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'staff_label' => ['nullable', 'string', 'max:120'],
            'trackable' => ['nullable', 'boolean'],
            'code_slow' => ['nullable', 'boolean'],
            'device_slow' => ['nullable', 'boolean'],
            'os_slow' => ['nullable', 'boolean'],
            'recorded_at' => ['nullable', 'date'],
            'extra' => ['nullable', 'array'],
            'problems' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:60'],
            'fences' => ['nullable', 'integer'],
            'factory_id' => ['nullable'],
        ]);

        $recordedAt = isset($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'])->timezone(self::TIMEZONE)
            : now(self::TIMEZONE);

        $extra = $validated['extra'] ?? [];
        if (isset($validated['problems'])) {
            $extra['problems'] = $validated['problems'];
        }
        if (isset($validated['source'])) {
            $extra['source'] = $validated['source'];
        }
        if (isset($validated['fences'])) {
            $extra['fences'] = $validated['fences'];
        }

        $log = GeofenceDeviceLog::create([
            'user_id' => $user->id,
            'team_id' => $teamId,
            'step' => $validated['step'],
            'category' => $validated['category'],
            'reason' => $validated['reason'] ?? null,
            'geofence_id' => $validated['geofence_id'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'duration_ms' => $validated['duration_ms'] ?? null,
            'battery_percent' => $validated['battery_percent'] ?? null,
            'battery_optimization_ignored' => $validated['battery_optimization_ignored'] ?? null,
            'gps_enabled' => $validated['gps_enabled'] ?? null,
            'location_permission' => $validated['location_permission'] ?? null,
            'background_location_permission' => $validated['background_location_permission'] ?? null,
            'device_model' => $validated['device_model'] ?? null,
            'staff_label' => $validated['staff_label'] ?? $user->name,
            'trackable' => $validated['trackable'] ?? true,
            'code_slow' => (bool) ($validated['code_slow'] ?? false),
            'device_slow' => (bool) ($validated['device_slow'] ?? false),
            'os_slow' => (bool) ($validated['os_slow'] ?? false),
            'extra' => $extra ?: null,
            'recorded_at' => $recordedAt,
            'received_at' => now(self::TIMEZONE),
        ]);

        return response()->json([
            'success' => true,
            'data' => $log->fresh()->toApiArray(),
        ], 201);
    }

    /**
     * Owner / manager: list recent logs for all (or one) staff members.
     */
    public function index(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanViewGeofenceEvents($request->user());

        $query = GeofenceDeviceLog::query()
            ->with('user')
            ->where('team_id', $teamId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('reason')) {
            $query->where('reason', $request->query('reason'));
        }
        if ($request->filled('step')) {
            $query->where('step', $request->query('step'));
        }

        if ($this->queryFlagTrue($request->query('problems'))) {
            $query->where(function ($q) {
                $q->whereIn('category', GeofenceDeviceLog::PROBLEM_CATEGORIES)
                    ->orWhere('trackable', false)
                    ->orWhere('code_slow', true)
                    ->orWhere('os_slow', true)
                    ->orWhere('device_slow', true);
            });
        }

        if ($request->filled('from')) {
            $from = Carbon::parse($request->query('from'), self::TIMEZONE)->startOfDay();
            $query->where('recorded_at', '>=', $from);
        }
        if ($request->filled('to')) {
            $to = Carbon::parse($request->query('to'), self::TIMEZONE);
            // Date-only → end of that day; datetime → as-is.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to'))) {
                $to = $to->endOfDay();
            }
            $query->where('recorded_at', '<=', $to);
        }

        $limit = min(500, max(1, (int) $request->query('limit', 100)));
        $logs = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $logs->map(fn (GeofenceDeviceLog $log) => $log->toApiArray()),
        ]);
    }

    private function queryFlagTrue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
