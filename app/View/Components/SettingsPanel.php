<?php

namespace App\View\Components;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * The header's settings panel: configuration links for the factory the user is in.
 *
 * Personal notification settings deliberately live in the account menu, not here —
 * this panel is only for things that apply to the whole factory.
 */
class SettingsPanel extends Component
{
    /**
     * @var list<array{title: string, icon: string, items: list<array{label: string, url: string}>}>
     */
    public array $groups;

    public function __construct(public ?Team $team)
    {
        $this->groups = $team ? self::groupsFor($team, auth()->user()) : [];
    }

    /**
     * The groups this user may see, with empty groups dropped.
     *
     * @return list<array{title: string, icon: string, items: list<array{label: string, url: string}>}>
     */
    public static function groupsFor(Team $team, User $user): array
    {
        $teamParam = ['team' => $team->id];
        $canViewFactoryConfig = Gate::forUser($user)->allows('view-factory-configuration');

        $devices = [];
        if ($user->isSuperadmin() && ! $team->is_fibre_only_factory) {
            $devices[] = ['label' => 'IoT devices', 'url' => route('devices.index', $teamParam)];
        }
        if ($canViewFactoryConfig && $team->has_water_meter_module) {
            $devices[] = ['label' => 'Water meters', 'url' => route('water-meters.index', $teamParam)];
            $devices[] = ['label' => 'Water tanks', 'url' => route('water-tanks.index', $teamParam)];
        }
        if ($canViewFactoryConfig && $team->has_pith_module && $team->has_pith_module_bales) {
            $devices[] = ['label' => 'Baling machines', 'url' => route('fibrebale-machines.index', $teamParam)];
        }
        if ($canViewFactoryConfig && $team->has_growbags_module) {
            $devices[] = ['label' => 'Growbag machines', 'url' => route('growbag-machines.index', $teamParam)];
        }

        $factory = [];
        if (Gate::forUser($user)->allows('edit-factory-configuration')) {
            $factory[] = ['label' => 'General', 'url' => route('teams.show', $team->id)];
        }
        if (Gate::forUser($user)->allows('shift_settings.view')) {
            $factory[] = ['label' => 'Shifts', 'url' => route('shifts.index', $teamParam)];
        }
        if ($team->has_geofencing_module && Gate::forUser($user)->allows('geofence_locations.view')) {
            $factory[] = ['label' => 'Geofence boundaries', 'url' => route('geofences.index', $teamParam)];
        }
        if (Gate::forUser($user)->allows('asset_categories.view')) {
            $factory[] = ['label' => 'Asset categories', 'url' => route('assetcategories.index', $teamParam)];
        }
        if (Gate::forUser($user)->allows('factory_customizations.view')) {
            $factory[] = ['label' => 'Customizations', 'url' => route('settings.index', $teamParam)];
        }

        $pithInventory = [];
        if ($team->has_pith_module && Gate::forUser($user)->allows('view-pith-locations')) {
            $pithInventory[] = ['label' => 'Groups', 'url' => route('pithlocationgroups.index', $teamParam)];
            $pithInventory[] = ['label' => 'Locations', 'url' => route('pithlocations.index', $teamParam)];
        }

        $access = [];
        if (Gate::forUser($user)->allows('view-users')) {
            $access[] = ['label' => 'Users', 'url' => route('users.index', $teamParam)];
        }
        if (Gate::forUser($user)->allows('view-role-matrix')) {
            $access[] = ['label' => 'Roles', 'url' => route('roles.index', $teamParam)];
        }
        if (Gate::forUser($user)->allows('view-staff')) {
            $access[] = ['label' => 'Staff', 'url' => route('staffs.index', $teamParam)];
        }

        $groups = [
            ['title' => 'Devices', 'icon' => 'fa-microchip', 'items' => $devices],
            ['title' => 'Factory', 'icon' => 'fa-industry', 'items' => $factory],
            ['title' => 'Pith inventory', 'icon' => 'fa-layer-group', 'items' => $pithInventory],
            ['title' => 'Team & access', 'icon' => 'fa-users', 'items' => $access],
        ];

        return array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));
    }

    public function render(): View
    {
        return view('components.settings-panel');
    }
}
