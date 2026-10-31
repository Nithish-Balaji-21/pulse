<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Shift extends Model
{
    public const DEFAULT_ANCHOR = '08:00:00';

    public const ANCHOR_SETTING_KEY = 'SHIFT_DAY_ANCHOR';

    public const MINUTES_PER_DAY = 1440;

    protected $table = 'pulse_shifts';

    protected $fillable = [
        'team_id',
        'name',
        'is_active',
        'start_time',
        'end_time',
        'created_by',
        'updated_by',
    ];

    public $timestamps = true;

    /** @var array<int, string> Per-request cache of team anchors, keyed by team id */
    protected static array $anchorCache = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function pithProductions(): HasMany
    {
        return $this->hasMany(PithProduction::class);
    }

    public function miniBaleProductions(): HasMany
    {
        return $this->hasMany(MiniBaleProduction::class);
    }

    public function scopeActiveForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId)->where('is_active', 1);
    }

    /**
     * Order shifts chronologically within the shift day (by minutes since the anchor).
     */
    public function scopeOrderedByOffset($query, string $anchor)
    {
        return $query->orderByRaw(
            'MOD(TIME_TO_SEC(start_time) - TIME_TO_SEC(?) + 86400, 86400)',
            [$anchor]
        );
    }

    /**
     * The team's shift-day anchor (start of its 24-hour production day) as HH:MM:SS.
     */
    public static function anchorForTeam(int $teamId): string
    {
        if (! isset(static::$anchorCache[$teamId])) {
            static::$anchorCache[$teamId] = DB::table('pulse_settings')
                ->where('team_id', $teamId)
                ->where('key', self::ANCHOR_SETTING_KEY)
                ->value('value') ?? self::DEFAULT_ANCHOR;
        }

        return static::$anchorCache[$teamId];
    }

    public static function forgetAnchorCache(?int $teamId = null): void
    {
        if ($teamId === null) {
            static::$anchorCache = [];
        } else {
            unset(static::$anchorCache[$teamId]);
        }
    }

    /**
     * The shift date a moment belongs to: a night shift running past midnight stays on the
     * day it started, which is how the summary tables key it.
     */
    public static function shiftDateFor(string $datetime, string $anchor): string
    {
        return Carbon::parse($datetime)->subSeconds(self::anchorSeconds($anchor))->toDateString();
    }

    /**
     * The same rule as {@see shiftDateFor()} for callers that resolve it in the database.
     */
    public static function shiftDateSqlFor(string $column, string $anchor): string
    {
        return 'DATE(DATE_SUB('.$column.', INTERVAL '.self::anchorSeconds($anchor).' SECOND))';
    }

    protected static function anchorSeconds(string $anchor): int
    {
        [$hours, $minutes, $seconds] = array_pad(explode(':', $anchor), 3, '0');

        return ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds;
    }

    /**
     * Minutes since the anchor for a clock time (0..1439).
     */
    public static function clockToOffset(string $time, string $anchor): int
    {
        return (self::timeToMinutes($time) - self::timeToMinutes($anchor) + self::MINUTES_PER_DAY)
            % self::MINUTES_PER_DAY;
    }

    /**
     * Clock time (HH:MM:SS) for a minutes-since-anchor offset. An offset of 1440 maps back to the anchor.
     */
    public static function offsetToClock(int $offset, string $anchor): string
    {
        $minutes = (self::timeToMinutes($anchor) + $offset) % self::MINUTES_PER_DAY;

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    protected static function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hours) * 60 + ((int) $minutes);
    }

    /**
     * Offsets of this shift within the shift day: starts are 0..1439, ends are 1..1440
     * (an end equal to the anchor means the shift runs to the end of the day).
     *
     * @return array{0: int, 1: int} [startOffset, endOffset]
     */
    public function offsetsFor(?string $anchor = null): array
    {
        $anchor ??= self::anchorForTeam($this->team_id);

        $startOffset = self::clockToOffset($this->start_time, $anchor);
        $endOffset = self::clockToOffset($this->end_time, $anchor);

        if ($endOffset === 0) {
            $endOffset = self::MINUTES_PER_DAY;
        }

        return [$startOffset, $endOffset];
    }

    /**
     * Whether the shift's start clock time falls on the calendar day after its shift date.
     */
    public function startsNextDay(?string $anchor = null): bool
    {
        $anchor ??= self::anchorForTeam($this->team_id);

        return self::timeToMinutes($this->start_time) < self::timeToMinutes($anchor);
    }

    /**
     * Whether the shift's end clock time falls on the calendar day after its shift date.
     */
    public function endsNextDay(?string $anchor = null): bool
    {
        $anchor ??= self::anchorForTeam($this->team_id);

        return self::timeToMinutes($this->end_time) <= self::timeToMinutes($anchor);
    }

    /**
     * Whether a moment belongs to this shift (start inclusive, end exclusive).
     */
    public function containsMoment($timestamp, ?string $anchor = null): bool
    {
        $anchor ??= self::anchorForTeam($this->team_id);

        $offset = self::clockToOffset(Carbon::parse($timestamp)->format('H:i:s'), $anchor);
        [$startOffset, $endOffset] = $this->offsetsFor($anchor);

        return $offset >= $startOffset && $offset < $endOffset;
    }

    /**
     * Physical start/end datetimes of this shift on a given shift date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowOn(string $shiftDate, ?string $anchor = null): array
    {
        $anchor ??= self::anchorForTeam($this->team_id);

        $start = Carbon::parse("$shiftDate {$this->start_time}");
        $end = Carbon::parse("$shiftDate {$this->end_time}");

        if ($this->startsNextDay($anchor)) {
            $start->addDay();
        }
        if ($this->endsNextDay($anchor)) {
            $end->addDay();
        }
        // Safety net for schedules that pre-date anchor validation
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }
}
