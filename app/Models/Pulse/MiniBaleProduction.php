<?php

namespace App\Models\Pulse;

use App\Models\Staff;
use App\Services\Pulse\ProductionEntryPolicy;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiniBaleProduction extends Model
{
    use HasFactory;

    public static bool $reconcilesStock = true;

    private static ?bool $hasStockColumns = null;

    protected $table = 'pulse_summary_mini_bales';

    protected $fillable = [
        'team_id',
        'staff_id',
        'shift_date',
        'device_id',
        'start_time',
        'end_time',
        'bales_produced',
        'bales_expected',
        'sold',
        'correction',
        'correction_comment',
        'bales_in_stock',
        'comments',
        'minibale_machine_id',
        'machine_name',
        'created_by',
        'updated_by',
    ];

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'sold' => 'integer',
            'correction' => 'integer',
            'bales_in_stock' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $model) => $model->reconcileAfterChange());
        static::deleted(fn (self $model) => $model->reconcileAfterChange());
    }

    public static function entryPolicyFor(int $teamId): ProductionEntryPolicy
    {
        return ProductionEntryPolicy::forTeam($teamId, ProductionEntryPolicyKeys::BALES);
    }

    public function entryPolicy(): ProductionEntryPolicy
    {
        return self::entryPolicyFor((int) $this->team_id);
    }

    public static function minEditableDate(int $teamId): string
    {
        return self::entryPolicyFor($teamId)->minShiftDate();
    }

    public function canEditRecord(): bool
    {
        return $this->entryPolicy()->canEdit((string) $this->shift_date);
    }

    public function canDeleteRecord(): bool
    {
        return $this->entryPolicy()->canDelete((string) $this->shift_date);
    }

    public function isEditable(): bool
    {
        return $this->canEditRecord();
    }

    public static function reconcileInStock(int $teamId, string $fromDate): void
    {
        if (! self::hasStockColumns()) {
            return;
        }

        DB::statement('CALL reconcile_bales_stock(?, ?)', [$teamId, $fromDate]);
    }

    public static function closingStockAt(int $teamId, string $date): int
    {
        return (int) (static::where('team_id', $teamId)
            ->whereDate('shift_date', '<=', $date)
            ->orderByDesc('shift_date')
            ->value('bales_in_stock') ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public static function closingStockByDate(int $teamId, string $from, string $to): array
    {
        return static::where('team_id', $teamId)
            ->whereBetween('shift_date', [$from, $to])
            ->get(['shift_date', 'bales_in_stock'])
            ->mapWithKeys(fn ($row) => [
                Carbon::parse($row->shift_date)->toDateString() => (int) $row->bales_in_stock,
            ])
            ->all();
    }

    public static function recordStockTake(int $teamId, string $date, int $level, ?string $comment = null): void
    {
        $row = static::firstOrCreate(
            ['team_id' => $teamId, 'shift_date' => $date],
            ['bales_produced' => 0, 'correction' => 0]
        );

        static::reconcileInStock($teamId, $date);
        $row->refresh();

        $uncorrected = (int) $row->bales_in_stock - (int) $row->correction;
        $correction = $level - $uncorrected;

        if ($correction === (int) $row->correction) {
            return;
        }

        $row->update([
            'correction' => $correction,
            'correction_comment' => $comment,
        ]);
    }

    public function producedForStock(): int
    {
        if ((int) $this->bales_produced > 0) {
            return (int) $this->bales_produced;
        }

        return (int) ($this->bales_expected ?? 0);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    private function reconcileAfterChange(): void
    {
        if (! static::$reconcilesStock || ! $this->team_id || ! $this->shift_date) {
            return;
        }

        if (! self::hasStockColumns()) {
            return;
        }

        $dates = array_filter([
            $this->toDateString($this->shift_date),
            $this->toDateString($this->getOriginal('shift_date')),
        ]);

        if ($dates === []) {
            return;
        }

        static::reconcileInStock((int) $this->team_id, min($dates));
    }

    private function toDateString($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }

    private static function hasStockColumns(): bool
    {
        if (self::$hasStockColumns === null) {
            self::$hasStockColumns = Schema::hasColumn('pulse_summary_mini_bales', 'sold');
        }

        return self::$hasStockColumns;
    }
}
