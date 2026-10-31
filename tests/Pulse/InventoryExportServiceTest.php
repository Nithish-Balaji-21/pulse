<?php

use App\Models\Pulse\HusksDailyStock;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use App\Services\Pulse\InventoryExportService;
use App\Services\Pulse\PithInventoryExportService;
use Illuminate\Support\Carbon;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Inventory Export Test '.uniqid());
    $this->service = new InventoryExportService(new PithInventoryExportService);
});

it('builds husk inventory report with opening stock, received, correction, and consumed values', function () {
    HusksDailyStock::create([
        'team_id' => $this->teamId,
        'date' => '2026-08-10',
        'opening_stock' => 1000.0,
        'husks_received' => 500.0,
        'husks_correction' => 50.0,
        'closing_stock' => 1200.0,
        'correction_comment' => 'Stock adjustment after weighment',
    ]);

    $report = $this->service->buildReport(
        'husk',
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10')
    );

    expect($report['module'])->toBe('husk')
        ->and($report['unit_label'])->toBe('CFT')
        ->and($report['totals']['opening_stock'])->toBe(1000.0)
        ->and($report['totals']['inward'])->toBe(500.0)
        ->and($report['totals']['correction'])->toBe(50.0)
        ->and($report['totals']['outward'])->toBe(350.0) // 1000 + 500 + 50 - 1200
        ->and($report['totals']['closing_stock'])->toBe(1200.0);
});

it('carries forward previous day closing stock for bales inventory', function () {
    MiniBaleProduction::$reconcilesStock = false;

    MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-09',
        'start_time' => '2026-08-09 08:00:00',
        'end_time' => '2026-08-09 18:00:00',
        'bales_expected' => 50,
        'bales_produced' => 50,
        'sold' => 20,
        'correction' => 0,
        'bales_in_stock' => 230,
    ]);

    MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-10',
        'start_time' => '2026-08-10 08:00:00',
        'end_time' => '2026-08-10 18:00:00',
        'bales_expected' => 40,
        'bales_produced' => 40,
        'sold' => 10,
        'correction' => 0,
        'bales_in_stock' => 260,
    ]);

    MiniBaleProduction::$reconcilesStock = true;

    $report = $this->service->buildReport(
        'bales',
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10')
    );

    expect($report['module'])->toBe('bales')
        ->and($report['totals']['opening_stock'])->toBe(230)
        ->and($report['totals']['inward'])->toBe(40)
        ->and($report['totals']['outward'])->toBe(10)
        ->and($report['totals']['closing_stock'])->toBe(260);
});

it('builds blocks inventory report dataset', function () {
    PithBlocksProduction::$reconcilesStock = false;

    PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-10',
        'start_time' => '2026-08-10 08:00:00',
        'end_time' => '2026-08-10 18:00:00',
        'blocks_expected' => 100,
        'blocks_produced' => 100,
        'sold' => 80,
        'correction' => -10,
        'blocks_in_stock' => 510,
    ]);

    // Seed prior closing so opening carries into the report day.
    PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-09',
        'start_time' => '2026-08-09 08:00:00',
        'end_time' => '2026-08-09 18:00:00',
        'blocks_expected' => 0,
        'blocks_produced' => 0,
        'sold' => 0,
        'correction' => 0,
        'blocks_in_stock' => 500,
    ]);

    PithBlocksProduction::$reconcilesStock = true;

    $report = $this->service->buildReport(
        'blocks',
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10')
    );

    expect($report['module'])->toBe('blocks')
        ->and($report['totals']['opening_stock'])->toBe(500)
        ->and($report['totals']['inward'])->toBe(100)
        ->and($report['totals']['correction'])->toBe(-10)
        ->and($report['totals']['outward'])->toBe(80)
        ->and($report['totals']['closing_stock'])->toBe(510);
});

it('rolls up inventory rows by month when date range exceeds 30 days', function () {
    $report = $this->service->buildReport(
        'husk',
        $this->teamId,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-02-15')
    );

    expect($report['is_month_view'])->toBeTrue()
        ->and(count($report['rows']))->toBe(2);
});
