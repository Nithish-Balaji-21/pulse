<?php

use App\Models\Team;
use App\Services\ExpenseConnection;
use Illuminate\Support\Facades\Http;

it('never connects other Pulse factories even with a factory-map override', function () {
    $team = new Team(['name' => 'Hexagro Fibre']);
    $team->id = 11;
    config(['expenses.url' => 'https://expenses.coiiot.in', 'expenses.api_key' => str_repeat('k', 64), 'expenses.factories' => [11 => 'Alam Cocos']]);
    Http::fake();
    expect(app(ExpenseConnection::class)->connected($team))->toBeFalse();
    expect(app(ExpenseConnection::class)->report($team, '2026-10-01', '2026-10-08'))->toBeNull();
    Http::assertNothingSent();
});

it('does not connect without server credentials', function () {
    config(['expenses.api_key' => '']);
    expect(app(ExpenseConnection::class)->connected(new Team(['name' => 'Alam Cocos'])))->toBeFalse();
});
