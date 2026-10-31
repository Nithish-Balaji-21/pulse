<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pulse\Api\Concerns\AuthorizesGeofences;
use App\Models\Pulse\GeofenceEvent;
use App\Models\Pulse\GeofenceLocation;
use App\Services\NotificationSenderService;
use App\Support\Notifications\NotificationType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class GeofenceEventController extends Controller
{
    use AuthorizesGeofences;

    private const TIMEZONE = 'Asia/Kolkata';

    private const DUPLICATE_WINDOW_SECONDS = 5;

    private const JITTER_WINDOW_MINUTES = 5;

    /**
     * Active geofence locations for the authenticated factory member.
     */
    public function assigned(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();

        $locations = GeofenceLocation::query()
            ->where('team_id', $teamId)
            ->where('status', GeofenceLocation::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $locations->map(fn (GeofenceLocation $location) => $location->toApiArray()),
        ]);
    }

    /**
     * Record a single ENTER or EXIT event from the staff phone.
     */
    public function store(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $user = $request->user();

        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
            'event_type' => ['required', 'string', Rule::in([GeofenceEvent::TYPE_ENTER, GeofenceEvent::TYPE_EXIT])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => ['required', 'date'],
        ]);

        $location = GeofenceLocation::query()
            ->where('id', $validated['location_id'])
            ->where('team_id', $teamId)
            ->where('status', GeofenceLocation::STATUS_ACTIVE)
            ->first();

        if (! $location) {
            return response()->json([
                'success' => false,
                'message' => 'Geofence location not found for this factory.',
            ], 404);
        }

        $recordedAt = Carbon::parse($validated['recorded_at'])->timezone(self::TIMEZONE);

        $latest = GeofenceEvent::query()
            ->where('user_id', $user->id)
            ->where('team_id', $teamId)
            ->where('geofence_location_id', $location->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $nearDuplicate = GeofenceEvent::query()
            ->where('user_id', $user->id)
            ->where('team_id', $teamId)
            ->where('geofence_location_id', $location->id)
            ->where('event_type', $validated['event_type'])
            ->whereBetween('recorded_at', [
                $recordedAt->copy()->subSeconds(self::DUPLICATE_WINDOW_SECONDS),
                $recordedAt->copy()->addSeconds(self::DUPLICATE_WINDOW_SECONDS),
            ])
            ->exists();

        $isRapidJitter = $latest
            && $latest->event_type === $validated['event_type']
            && abs($latest->recorded_at->diffInMinutes($recordedAt)) < self::JITTER_WINDOW_MINUTES;

        $isSameDayDuplicateEnter = $latest
            && $validated['event_type'] === GeofenceEvent::TYPE_ENTER
            && $latest->event_type === GeofenceEvent::TYPE_ENTER
            && $latest->recorded_at->isSameDay($recordedAt);

        $isDuplicateExit = $latest
            && $validated['event_type'] === GeofenceEvent::TYPE_EXIT
            && $latest->event_type === GeofenceEvent::TYPE_EXIT
            && abs($latest->recorded_at->diffInMinutes($recordedAt)) < self::JITTER_WINDOW_MINUTES;

        if ($nearDuplicate || $isRapidJitter || $isSameDayDuplicateEnter || $isDuplicateExit) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'message' => 'Duplicate geofence event ignored.',
            ]);
        }

        // Close a dangling previous-day ENTER so next-day arrivals are not blocked
        // and Staff Tracking timelines do not stay open forever after a missed EXIT.
        if (
            $latest
            && $validated['event_type'] === GeofenceEvent::TYPE_ENTER
            && $latest->event_type === GeofenceEvent::TYPE_ENTER
            && ! $latest->recorded_at->isSameDay($recordedAt)
        ) {
            GeofenceEvent::create([
                'user_id' => $user->id,
                'team_id' => $teamId,
                'geofence_location_id' => $location->id,
                'event_type' => GeofenceEvent::TYPE_EXIT,
                'latitude' => $latest->latitude,
                'longitude' => $latest->longitude,
                'recorded_at' => $latest->recorded_at->copy()->endOfDay(),
                'received_at' => now(self::TIMEZONE),
            ]);
        }

        $event = GeofenceEvent::create([
            'user_id' => $user->id,
            'team_id' => $teamId,
            'geofence_location_id' => $location->id,
            'event_type' => $validated['event_type'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'recorded_at' => $recordedAt,
            'received_at' => now(self::TIMEZONE),
        ]);

        $this->notifyOwners($event->load(['location', 'user']));

        return response()->json([
            'success' => true,
            'data' => $this->eventPayload($event),
        ], 201);
    }

    /**
     * Owner view: ENTER/EXIT events for one staff member on one calendar day (IST).
     */
    public function staffEvents(Request $request, $team, $staff): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanViewGeofenceEvents($request->user());

        $staffUser = $this->teamMember($teamId, (int) $staff);
        if (! $staffUser) {
            return response()->json([
                'success' => false,
                'message' => 'Staff member not found in this factory.',
            ], 404);
        }

        $date = $request->query('date', now(self::TIMEZONE)->toDateString());
        try {
            $dayStart = Carbon::createFromFormat('Y-m-d', $date, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date. Use YYYY-MM-DD.',
            ], 422);
        }
        $dayEnd = $dayStart->copy()->endOfDay();

        $events = GeofenceEvent::query()
            ->with('location')
            ->where('team_id', $teamId)
            ->where('user_id', $staffUser->id)
            ->whereBetween('recorded_at', [$dayStart, $dayEnd])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events->map(fn (GeofenceEvent $event) => $this->eventPayload($event)),
        ]);
    }

    /**
     * Owner live map: staff whose latest event is ENTER (currently inside a geofence).
     */
    public function presence(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanViewGeofenceEvents($request->user());

        $latestIds = GeofenceEvent::query()
            ->selectRaw('MAX(id)')
            ->where('team_id', $teamId)
            ->groupBy('user_id');

        $events = GeofenceEvent::query()
            ->with(['user', 'location'])
            ->where('team_id', $teamId)
            ->whereIn('id', $latestIds)
            ->where('event_type', GeofenceEvent::TYPE_ENTER)
            ->orderBy('recorded_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events->map(function (GeofenceEvent $event) {
                $user = $event->user;

                return [
                    'user_id' => $event->user_id,
                    'event_type' => $event->event_type,
                    'recorded_at' => $event->recorded_at?->timezone(self::TIMEZONE)->toIso8601String(),
                    'event_latitude' => $event->latitude,
                    'event_longitude' => $event->longitude,
                    'user' => [
                        'id' => $user?->id,
                        'name' => $user?->name,
                        'email' => $user?->email,
                    ],
                    'location' => $event->location
                        ? $event->location->toApiArray()
                        : null,
                ];
            }),
        ]);
    }

    /**
     * Staff picker used by the owner history screen.
     */
    public function staff(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanViewGeofenceEvents($request->user());

        $users = DB::table('users')
            ->join('team_user', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $teamId)
            ->where('users.is_active', 1)
            ->orderBy('users.name')
            ->select('users.id', 'users.name', 'users.email', 'team_user.role')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users->map(fn ($user) => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ]),
        ]);
    }

    private function eventPayload(GeofenceEvent $event): array
    {
        $location = $event->location;

        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'recorded_at' => $event->recorded_at?->timezone(self::TIMEZONE)->toIso8601String(),
            'event_latitude' => $event->latitude,
            'event_longitude' => $event->longitude,
            'location' => $location ? $location->toApiArray() : null,
        ];
    }

    private function notifyOwners(GeofenceEvent $event): void
    {
        try {
            $user = $event->user;
            $location = $event->location;
            $action = $event->event_type === GeofenceEvent::TYPE_ENTER ? 'entered' : 'left';
            $place = $location?->name ?? 'a location';
            $when = $event->recorded_at?->timezone(self::TIMEZONE)->format('d M Y h:i A');
            $type = $event->event_type === GeofenceEvent::TYPE_ENTER
                ? NotificationType::GEOFENCE_ENTER
                : NotificationType::GEOFENCE_EXIT;

            app(NotificationSenderService::class)->notifyTeamUsers(
                (int) $event->team_id,
                ['owner'],
                ($user?->name ?? 'Staff')." {$action} {$place}",
                array_filter([
                    $when ? "Time: {$when}" : null,
                    $location?->type ? 'Type: '.$location->type : null,
                ]),
                now(self::TIMEZONE)->toDateString(),
                now(self::TIMEZONE)->toDateString(),
                true,
                $type
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send geofence event notification: '.$e->getMessage());
        }
    }
}
