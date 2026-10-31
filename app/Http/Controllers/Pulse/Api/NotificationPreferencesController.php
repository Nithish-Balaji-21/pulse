<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets the mobile app read and change what a user is notified about.
 */
class NotificationPreferencesController extends Controller
{
    public function __construct(private NotificationPreferenceService $preferences) {}

    /**
     * Preference matrix for the caller in their current factory.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->canViewNotificationPreferences(), 403);
        $teamId = (int) app('current.team')->id;
        $role = $this->preferences->roleFor($user->id, $teamId);

        return response()->json([
            'team_id' => $teamId,
            'team_name' => app('current.team')->name,
            'role' => $role,
            'is_superadmin' => (bool) $user->is_superadmin,
            // Staff and readers are never notified: the client should show a
            // note rather than an empty settings screen.
            'can_receive_notifications' => $this->preferences->canReceiveAnything($role, (bool) $user->is_superadmin),
            'groups' => $this->preferences->matrixFor($user, $teamId),
        ]);
    }

    /**
     * Update one or more toggles for the current factory.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.email' => ['sometimes', 'boolean'],
            'preferences.*.push' => ['sometimes', 'boolean'],
        ]);

        $user = Auth::user();
        abort_unless($user->canManageNotificationPreferences(), 403);
        $teamId = (int) app('current.team')->id;
        $role = $this->preferences->roleFor($user->id, $teamId);

        if (! $this->preferences->canReceiveAnything($role, (bool) $user->is_superadmin)) {
            return response()->json([
                'message' => 'Notifications go to owners, admins and supervisors only.',
            ], 403);
        }

        $unknown = array_diff(
            array_keys($validated['preferences']),
            $this->preferences->availableTypesForTeam($teamId)
        );

        if (! empty($unknown)) {
            return response()->json([
                'message' => 'Unknown notification types: '.implode(', ', $unknown),
            ], 422);
        }

        $this->preferences->setMany($user, $teamId, $validated['preferences']);

        return response()->json([
            'message' => 'Notification preferences updated.',
            'groups' => $this->preferences->matrixFor($user, $teamId),
        ]);
    }

    /**
     * Every factory a superadmin is notified about, with its mute state.
     */
    public function factories(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->is_superadmin) {
            return response()->json(['message' => 'Only superadmins can manage factory notifications.'], 403);
        }

        $factories = $this->preferences->factoryOverviewFor($user)
            ->map(fn (array $entry) => [
                'team_id' => (int) $entry['team']->id,
                'team_name' => $entry['team']->name,
                'enabled' => $entry['enabled'],
            ])
            ->values();

        return response()->json(['factories' => $factories]);
    }

    /**
     * Mute or unmute an entire factory for a superadmin.
     */
    public function updateFactory(Request $request, int $teamId): JsonResponse
    {
        $user = Auth::user();

        if (! $user->is_superadmin) {
            return response()->json(['message' => 'Only superadmins can manage factory notifications.'], 403);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->preferences->setFactoryEnabled($user, $teamId, (bool) $validated['enabled']);

        return response()->json([
            'message' => $validated['enabled']
                ? 'Notifications enabled for this factory.'
                : 'Notifications muted for this factory.',
            'team_id' => $teamId,
            'enabled' => (bool) $validated['enabled'],
        ]);
    }

    /**
     * Catalogue of notification types, for clients that render their own UI.
     */
    public function types(): JsonResponse
    {
        $teamId = (int) app('current.team')->id;

        $types = collect($this->preferences->availableTypesForTeam($teamId))
            ->map(function (string $type) {
                $definition = NotificationType::definition($type);

                return [
                    'type' => $type,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'group' => $definition['group'],
                    'channels' => $definition['channels'],
                ];
            })
            ->values();

        return response()->json(['types' => $types]);
    }
}
