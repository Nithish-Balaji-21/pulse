<?php

namespace App\Livewire;

use App\Models\Team;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Personal notification settings for the factory the user is currently in.
 *
 * Superadmins additionally get a factory list so they can mute the factories
 * they do not want to follow.
 */
class NotificationPreferences extends Component
{
    public int $teamId;

    public string $teamName = '';

    /**
     * @var array<string, array{email: bool, push: bool}>
     */
    public array $preferences = [];

    /**
     * @var array<int, bool> keyed by team id
     */
    public array $factoryEnabled = [];

    /**
     * @var array<int, array{id: int, name: string}>
     */
    public array $factories = [];

    public bool $isSuperadmin = false;

    public ?string $teamRole = null;

    /**
     * False for staff and readers, who are never notified.
     */
    public bool $canReceiveNotifications = false;

    public function mount(NotificationPreferenceService $service): void
    {
        $team = app()->has('current.team') ? app('current.team') : Auth::user()->activeTeam();

        abort_if(! $team, 404);
        abort_unless($this->user()->canViewNotificationPreferences(), 403);

        $this->teamId = (int) $team->id;
        $this->teamName = (string) $team->name;
        $this->isSuperadmin = (bool) $this->user()->is_superadmin;
        $this->teamRole = $service->roleFor($this->user()->id, $this->teamId);
        $this->canReceiveNotifications = $service->canReceiveAnything($this->teamRole, $this->isSuperadmin);

        $this->loadPreferences($service);
        $this->loadFactories($service);
    }

    public function render()
    {
        return view('livewire.notification-preferences', [
            'matrix' => app(NotificationPreferenceService::class)->matrixFor($this->user(), $this->teamId),
        ]);
    }

    /**
     * Persist whichever toggle the user just flipped.
     */
    public function updated(string $property, $value): void
    {
        $service = app(NotificationPreferenceService::class);
        $segments = explode('.', $property);

        if ($segments[0] === 'preferences' && count($segments) === 3) {
            abort_unless($this->user()->canManageNotificationPreferences(), 403);
            [, $type, $channel] = $segments;

            $service->set($this->user(), $this->teamId, $type, $channel, (bool) $value);
            $this->loadPreferences($service);
            $this->dispatch('saved');

            return;
        }

        if ($segments[0] === 'factoryEnabled' && count($segments) === 2) {
            abort_unless($this->user()->is_superadmin, 403);

            $teamId = (int) $segments[1];

            $service->setFactoryEnabled($this->user(), $teamId, (bool) $value);
            $this->loadFactories($service);

            if ($teamId === $this->teamId) {
                $this->loadPreferences($service);
            }

            $this->dispatch('saved');
        }
    }

    private function loadPreferences(NotificationPreferenceService $service): void
    {
        $this->preferences = [];

        foreach ($service->matrixFor($this->user(), $this->teamId) as $group) {
            foreach ($group['types'] as $type) {
                $this->preferences[$type['type']] = [
                    NotificationType::CHANNEL_EMAIL => $type['email'],
                    NotificationType::CHANNEL_PUSH => $type['push'],
                ];
            }
        }
    }

    private function loadFactories(NotificationPreferenceService $service): void
    {
        if (! $this->isSuperadmin) {
            $this->factories = [];
            $this->factoryEnabled = [];

            return;
        }

        $factories = [];
        $enabled = [];

        foreach ($service->factoryOverviewFor($this->user()) as $entry) {
            /** @var Team $team */
            $team = $entry['team'];
            $factories[] = ['id' => (int) $team->id, 'name' => (string) $team->name];
            $enabled[(int) $team->id] = $entry['enabled'];
        }

        $this->factories = $factories;
        $this->factoryEnabled = $enabled;
    }

    private function user(): User
    {
        return Auth::user();
    }
}
