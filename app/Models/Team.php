<?php

namespace App\Models;

use App\Models\Pulse\Device;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\Pulse\PithProduction;
use App\Models\Pulse\PulseTeamRole;
use App\Models\Pulse\Shift;
use App\Support\Pulse\GeofencingModule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    use HasFactory;

    protected $appends = [
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'pincode',
        'coordinates',
        'license_valid_till',
        'is_active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * Only `name` and `products` remain on `teams`; address, licensing, and
     * activation moved to the `team_address` / `team_pulse` relations and
     * must be written through those models.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'products',
    ];

    /**
     * Per-request memoized cache for showActuals(). Not persisted or shared across requests.
     */
    protected ?bool $showActualsCache = null;

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'products' => 'array',
        ];
    }

    /**
     * Get devices in the team
     */
    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function pithProductions()
    {
        return $this->hasMany(PithProduction::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function pithLocations()
    {
        return $this->hasMany(PithLocation::class);
    }

    public function pithLocationGroups()
    {
        return $this->hasMany(PithLocationGroup::class);
    }

    public function staffs()
    {
        return $this->hasMany(Staff::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot('role');
    }

    /**
     * Get the owner of the team through the team_user pivot.
     * Overrides Jetstream's default user_id-based owner lookup.
     */
    public function owner()
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->wherePivot('role', 'owner')
            ->limit(1);
    }

    /**
     * Get the owner model (single User) via the pivot.
     */
    public function getOwnerAttribute(): ?User
    {
        return $this->owner()->first();
    }

    public function address()
    {
        return $this->hasOne(TeamAddress::class)->where('type', 'factory');
    }

    public function pulse()
    {
        return $this->hasOne(TeamPulse::class);
    }

    public function pulseTeamRoles()
    {
        return $this->hasMany(PulseTeamRole::class);
    }

    /**
     * Limit to teams with an active Pulse subscription.
     *
     * `is_active` lives on `team_pulse`, not `teams` — the model attribute is a
     * read-only accessor, so it can only be filtered through the relation.
     */
    public function scopeActivePulse(Builder $query): Builder
    {
        return $query->whereHas('pulse', fn (Builder $pulseQuery) => $pulseQuery->where('is_active', 1));
    }

    /**
     * Whether this team may use the Pulse app at all.
     *
     * Teams without a `team_pulse` row are denied; a Pulse team must also be
     * active with an unexpired licence. This is the single source of truth for
     * both the web (`EnsureTeamIsActive`) and the API (`VerifyCurrentTeam`)
     * guards — keep them agreeing.
     *
     * `license_valid_till` is inclusive: a licence valid till the 30th still
     * grants access throughout the 30th and lapses on the 31st. This matches
     * the cutoff `DeactivateExpiredTeams` uses to flip `is_active` off.
     */
    public function hasPulseAccess(): bool
    {
        $pulse = $this->pulse;

        if (! $pulse || ! $pulse->is_active) {
            return false;
        }

        return ! $pulse->license_valid_till
            || ! Carbon::parse($pulse->license_valid_till)->lt(Carbon::today());
    }

    public function getActiveModuleAttribute(): string
    {
        $products = $this->pulse->modules['pulse']['modules'] ?? [];
        if (! empty($products['pith']['is_active'])) {
            return 'pith';
        }
        if (! empty($products['fibre']['is_active'])) {
            return 'fibre';
        }
        if (! empty($products['growbags']['is_active'])) {
            return 'growbag';
        }
        if (! empty($products['pith']['submodules']['bales'])) {
            return 'pithbales';
        }
        if (! empty($products['pith']['submodules']['blocks'])) {
            return 'pithblocks';
        }
        if (! empty($products['water_meter']['is_active'])) {
            return 'water';
        }

        return 'pith';
    }

    public function getHasPithModuleAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['pith']['is_active'] ?? false;
    }

    public function getHasPithModuleBlocksAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['pith']['submodules']['blocks'] ?? false;
    }

    public function getHasPithModuleBalesAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['pith']['submodules']['bales'] ?? false;
    }

    public function getHasFibreModuleAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['fibre']['is_active'] ?? false;
    }

    /**
     * Fibre-only factory: fibre module active without pith.
     */
    public function getIsFibreOnlyFactoryAttribute(): bool
    {
        return $this->has_fibre_module && ! $this->has_pith_module;
    }

    /**
     * Device-only factory: everything it produces is counted by a machine, with no pith side.
     *
     * Fibre and growbags are both single production lines whose output the device reports, so a
     * factory running either (or both) without pith has no manually-recorded production — and
     * therefore no per-head labour to record against it. A strict superset of
     * `is_fibre_only_factory`, which stays as-is: that one also governs IoT device configuration,
     * where growbag factories do need access.
     */
    public function getIsDeviceOnlyFactoryAttribute(): bool
    {
        return ($this->has_fibre_module || $this->has_growbags_module) && ! $this->has_pith_module;
    }

    public function getHasFibreModuleWeightAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['fibre']['submodules']['weight'] ?? false;
    }

    public function getHasGrowbagsModuleAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['growbags']['is_active'] ?? false;
    }

    public function getHasHusksStockModuleAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['husks_stock']['is_active'] ?? false;
    }

    public function getHasWaterMeterModuleAttribute(): bool
    {
        return $this->pulse?->modules['pulse']['modules']['water_meter']['is_active'] ?? false;
    }

    /**
     * Labour/attendance is a pith-factory concern and is never available to a device-only
     * factory, whatever the stored flag says — the same rule the role matrix applies via
     * `not.device_only`. Checked here too because this accessor is what the nav reads, and a
     * superadmin's `view-attendance` gate short-circuits to true before the role matrix is
     * consulted.
     */
    public function getHasAttendanceModuleAttribute(): bool
    {
        if ($this->is_device_only_factory) {
            return false;
        }

        return $this->pulse?->modules['pulse']['modules']['attendance']['is_active'] ?? true;
    }

    public function getHasGeofencingModuleAttribute(): bool
    {
        return GeofencingModule::isActive($this);
    }

    public function getGeofencingMaxBoundariesAttribute(): int
    {
        return GeofencingModule::maxBoundaries($this);
    }

    public function getHasReportsModuleAttribute(): bool
    {
        return $this->has_pith_module
            || $this->has_pith_module_bales
            || $this->has_pith_module_blocks
            || $this->has_attendance_module;
    }

    /**
     * Whether manually-entered "Actuals" should be captured/displayed for Pith,
     * Fibre Mini-Bale, and Pith Blocks. Defaults true (fails open) if the
     * SHOW_ACTUALS setting row is missing for this team.
     */
    public function showActuals(): bool
    {
        if ($this->showActualsCache === null) {
            $this->showActualsCache = Settings::where('team_id', $this->id)
                ->where('module', 'pith')
                ->where('key', 'SHOW_ACTUALS')
                ->value('value') !== 'no';
        }

        return $this->showActualsCache;
    }

    public function getDisplayNameAttribute()
    {
        $email = Auth::user()?->email;

        if ($email !== null && $email === env('DEMO_USER_EMAIL')) {
            return match ($this->id) {
                2 => 'Demo Pith Factory',
                9 => 'Demo Fibre Factory',
                default => $this->name,
            };
        }

        return $this->name;
    }

    public function getAddressLine1Attribute()
    {
        return $this->address?->address_line_1;
    }

    public function getAddressLine2Attribute()
    {
        return $this->address?->address_line_2;
    }

    public function getCityAttribute()
    {
        return $this->address?->city;
    }

    public function getStateAttribute()
    {
        return $this->address?->state;
    }

    public function getPincodeAttribute()
    {
        return $this->address?->pincode;
    }

    public function getCoordinatesAttribute()
    {
        return $this->pulse?->coordinates;
    }

    /**
     * The factory's configured location parsed into a normalised pair.
     * Returns null when the team has no coordinates, or they are malformed.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function coordinatePair(): ?array
    {
        return self::parseCoordinates($this->coordinates);
    }

    /**
     * Parse a "lat,lng" string into a normalised pair.
     * Returns null when the string is malformed or either value is out of range.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parseCoordinates(?string $raw): ?array
    {
        if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', (string) $raw, $matches)) {
            return null;
        }

        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return [
            'lat' => $latitude,
            'lng' => $longitude,
        ];
    }

    public function getLicenseValidTillAttribute()
    {
        $date = $this->pulse?->license_valid_till;

        return $date ? (Carbon::parse($date))->format('Y-m-d') : null;
    }

    public function getIsActiveAttribute()
    {
        return $this->pulse?->is_active ?? true;
    }
}
