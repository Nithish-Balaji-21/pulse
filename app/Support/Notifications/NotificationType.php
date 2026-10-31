<?php

namespace App\Support\Notifications;

/**
 * Central catalogue of every notification a factory user can receive.
 *
 * Each entry drives three things at once: which team roles are subscribed by
 * default, which channels the notification is actually delivered on, and how
 * the type is presented in the preference screens.
 */
class NotificationType
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_PUSH = 'push';

    public const PRODUCTION_SUMMARY_DAILY = 'PRODUCTION_SUMMARY_DAILY';

    public const PRODUCTION_SUMMARY_WEEKLY = 'PRODUCTION_SUMMARY_WEEKLY';

    public const PRODUCTION_SUMMARY_MONTHLY = 'PRODUCTION_SUMMARY_MONTHLY';

    public const PRODUCTION_SUMMARY_YEARLY = 'PRODUCTION_SUMMARY_YEARLY';

    public const PITH_LOCATION_CLOSED = 'PITH_LOCATION_CLOSED';

    public const MACHINE_ON = 'MACHINE_ON';

    public const MACHINE_OFF = 'MACHINE_OFF';

    public const MACHINE_SHUTDOWN = 'MACHINE_SHUTDOWN';

    public const WATER_TANK_LOW = 'WATER_TANK_LOW';

    public const WATER_TANK_OVERFLOW = 'WATER_TANK_OVERFLOW';

    public const LICENSE_EXPIRY = 'LICENSE_EXPIRY';

    public const GEOFENCE_ENTER = 'GEOFENCE_ENTER';

    public const GEOFENCE_EXIT = 'GEOFENCE_EXIT';

    public const GENERAL = 'GENERAL';

    /**
     * Legacy single production-summary key, split into the four period keys.
     */
    public const LEGACY_PRODUCTION_SUMMARY = 'PRODUCTION_SUMMARY';

    /**
     * The only roles that may receive a factory notification at all.
     *
     * Staff and readers are excluded outright: this is a hard gate, not a
     * default, so neither a stored preference row nor a role change that
     * leaves rows behind can put them back on a recipient list.
     *
     * @var array<int, string>
     */
    public const NOTIFIABLE_ROLES = ['owner', 'admin', 'supervisor'];

    /**
     * Roles addressed on the "To" line of an email; supervisors are copied.
     *
     * @var array<int, string>
     */
    public const PRIMARY_EMAIL_ROLES = ['owner', 'admin'];

    /**
     * @var array<string, array{
     *     label: string,
     *     description: string,
     *     group: string,
     *     channels: array<int, string>,
     *     roles: array<int, string>,
     *     default_roles: array<int, string>,
     *     requires: ?string,
     *     configurable: bool
     * }>|null
     */
    private static ?array $definitions = null;

    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     group: string,
     *     channels: array<int, string>,
     *     roles: array<int, string>,
     *     default_roles: array<int, string>,
     *     requires: ?string,
     *     configurable: bool
     * }>
     */
    public static function all(): array
    {
        return self::$definitions ??= [
            self::PRODUCTION_SUMMARY_DAILY => [
                'label' => 'Daily production summary',
                'description' => "Yesterday's production, water, attendance and stock figures.",
                'group' => 'Production',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::PRODUCTION_SUMMARY_WEEKLY => [
                'label' => 'Weekly production summary',
                'description' => 'Previous week (Monday to Sunday) production roll-up.',
                'group' => 'Production',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::PRODUCTION_SUMMARY_MONTHLY => [
                'label' => 'Monthly production summary',
                'description' => 'Previous calendar month production roll-up.',
                'group' => 'Production',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::PRODUCTION_SUMMARY_YEARLY => [
                'label' => 'Yearly production summary',
                'description' => 'Previous calendar year production roll-up.',
                'group' => 'Production',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::PITH_LOCATION_CLOSED => [
                'label' => 'Pith location closed',
                'description' => 'Sent when a pith location is emptied and its stock is closed.',
                'group' => 'Inventory',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::MACHINE_ON => [
                'label' => 'Machine started',
                'description' => 'Push alert when a machine starts running.',
                'group' => 'Machines',
                'channels' => [self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::MACHINE_OFF => [
                'label' => 'Machine stopped',
                'description' => 'Push alert when a machine stops running.',
                'group' => 'Machines',
                'channels' => [self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::MACHINE_SHUTDOWN => [
                'label' => 'Machine shutdown',
                'description' => 'Push alert when a machine is shut down for the day.',
                'group' => 'Machines',
                'channels' => [self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => true,
            ],
            self::WATER_TANK_LOW => [
                'label' => 'Water tank low',
                'description' => 'Alert when a water tank level drops below the low threshold.',
                'group' => 'Water',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => 'water_tanks',
                'configurable' => true,
            ],
            self::WATER_TANK_OVERFLOW => [
                'label' => 'Water tank overflow',
                'description' => 'Alert when a water tank crosses the overflow threshold.',
                'group' => 'Water',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => 'water_tanks',
                'configurable' => true,
            ],
            self::LICENSE_EXPIRY => [
                'label' => 'Licence expiry warning',
                'description' => 'Reminder a week before the factory licence expires, and again the day before.',
                'group' => 'Account',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                // The licence is the owner's to renew, so only they are
                // subscribed by default. An admin may follow it if they want
                // to; a supervisor has no part in it and is not offered it.
                'roles' => ['owner', 'admin'],
                'default_roles' => ['owner'],
                'requires' => null,
                'configurable' => true,
            ],
            self::GEOFENCE_ENTER => [
                'label' => 'Staff entered a location',
                'description' => 'Push alert when factory staff enter a geofence boundary.',
                'group' => 'Staff location',
                'channels' => [self::CHANNEL_PUSH],
                'roles' => ['owner'],
                'default_roles' => ['owner'],
                'requires' => null,
                'configurable' => true,
            ],
            self::GEOFENCE_EXIT => [
                'label' => 'Staff left a location',
                'description' => 'Push alert when factory staff leave a geofence boundary.',
                'group' => 'Staff location',
                'channels' => [self::CHANNEL_PUSH],
                'roles' => ['owner'],
                'default_roles' => ['owner'],
                'requires' => null,
                'configurable' => true,
            ],
            self::GENERAL => [
                'label' => 'Other factory notifications',
                'description' => 'Anything that does not belong to a dedicated category.',
                'group' => 'Other',
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
                'roles' => self::NOTIFIABLE_ROLES,
                'default_roles' => self::NOTIFIABLE_ROLES,
                'requires' => null,
                'configurable' => false,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Types a user is allowed to switch on and off from the preference screens.
     *
     * @return array<int, string>
     */
    public static function configurableKeys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $definition) => $definition['configurable']));
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    /**
     * @return array{
     *     label: string,
     *     description: string,
     *     group: string,
     *     channels: array<int, string>,
     *     default_roles: array<int, string>,
     *     requires: ?string,
     *     configurable: bool
     * }
     */
    public static function definition(string $type): array
    {
        return self::all()[$type] ?? self::all()[self::GENERAL];
    }

    public static function label(string $type): string
    {
        return self::definition($type)['label'];
    }

    public static function group(string $type): string
    {
        return self::definition($type)['group'];
    }

    /**
     * @return array<int, string>
     */
    public static function channels(string $type): array
    {
        return self::definition($type)['channels'];
    }

    public static function supportsChannel(string $type, string $channel): bool
    {
        return in_array($channel, self::channels($type), true);
    }

    /**
     * Whether a team member holding $role is allowed to receive notifications
     * at all. Staff and readers never are, whatever their stored preferences
     * say — see NOTIFIABLE_ROLES.
     */
    public static function roleCanReceive(?string $role): bool
    {
        return $role !== null && in_array($role, self::NOTIFIABLE_ROLES, true);
    }

    /**
     * The roles a single notification type is open to.
     *
     * Narrower than NOTIFIABLE_ROLES for a type that is not every role's
     * business — see LICENSE_EXPIRY.
     *
     * @return array<int, string>
     */
    public static function eligibleRoles(string $type): array
    {
        return self::definition($type)['roles'];
    }

    /**
     * Whether a team member holding $role may receive $type at all.
     *
     * Like roleCanReceive() this is a gate rather than a default: a role that
     * is not eligible cannot subscribe itself, and is not shown the type on the
     * preference screen.
     */
    public static function roleCanReceiveType(string $type, ?string $role): bool
    {
        return self::roleCanReceive($role)
            && in_array($role, self::eligibleRoles($type), true);
    }

    /**
     * Whether a team member holding $role is subscribed to $type out of the box.
     */
    public static function isDefaultForRole(string $type, ?string $role): bool
    {
        if (! self::roleCanReceiveType($type, $role)) {
            return false;
        }

        return in_array($role, self::definition($type)['default_roles'], true);
    }

    /**
     * Map a production summary period ("daily", "weekly", ...) to its type key.
     */
    public static function forSummaryPeriod(?string $period): string
    {
        return match (strtolower((string) $period)) {
            'weekly' => self::PRODUCTION_SUMMARY_WEEKLY,
            'monthly' => self::PRODUCTION_SUMMARY_MONTHLY,
            'yearly' => self::PRODUCTION_SUMMARY_YEARLY,
            default => self::PRODUCTION_SUMMARY_DAILY,
        };
    }

    /**
     * Types that are delivered instantly and are therefore not written to the
     * in-app notification feed.
     *
     * @return array<int, string>
     */
    public static function transientAlertTypes(): array
    {
        return [
            self::MACHINE_ON,
            self::MACHINE_OFF,
            self::MACHINE_SHUTDOWN,
            self::WATER_TANK_LOW,
            self::WATER_TANK_OVERFLOW,
            self::GEOFENCE_ENTER,
            self::GEOFENCE_EXIT,
        ];
    }

    /**
     * Groups in the order they should be rendered in the preference screens.
     *
     * @return array<int, string>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::all() as $definition) {
            if ($definition['configurable'] && ! in_array($definition['group'], $groups, true)) {
                $groups[] = $definition['group'];
            }
        }

        return $groups;
    }
}
