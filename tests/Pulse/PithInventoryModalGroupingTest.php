<?php

use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Traits\ManagePithInventory;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * Minimal stand-in for the Livewire components that host the entry modal — both
 * PithCreateAndEdit and PithProductionList feed the trait the same two properties.
 */
class ModalGroupingHost
{
    use ManagePithInventory;

    public array $pithLocations = [];

    public array $pithLocationLoads = [];
}

function modalTeam(): int
{
    return TeamFactory::create('Modal Factory '.uniqid());
}

function modalLocation(int $teamId, string $name, ?int $groupId = null): array
{
    $location = PithLocation::create([
        'team_id' => $teamId,
        'group_id' => $groupId,
        'name' => $name,
        'is_active' => true,
    ]);

    return $location->fresh()->toArray();
}

beforeEach(function () {
    $this->teamId = modalTeam();
    $this->host = new ModalGroupingHost;
});

it('folds modal locations into their groups with ungrouped last', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Zulu Group']);

    $grouped = modalLocation($this->teamId, 'grouped yard', $group->id);
    $grouped['group_name'] = 'Zulu Group';
    $grouped['group_display_order'] = 0;

    $this->host->pithLocations = [$grouped, modalLocation($this->teamId, 'stray yard')];

    $groups = $this->host->groupedPithLocations();

    expect($groups)->toHaveCount(2);
    expect($groups[0]['name'])->toBe('Zulu Group');
    expect($groups[1]['name'])->toBe('Ungrouped');
    expect($groups[1]['is_ungrouped'])->toBeTrue();
});

it('subtotals the cft assigned to each group', function () {
    $alam = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $hexagro = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Hexagro']);

    $a = modalLocation($this->teamId, 'alam east', $alam->id);
    $a['group_name'] = 'Alam';
    $b = modalLocation($this->teamId, 'alam north', $alam->id);
    $b['group_name'] = 'Alam';
    $c = modalLocation($this->teamId, 'hexagro bunker', $hexagro->id);
    $c['group_name'] = 'Hexagro';

    $this->host->pithLocations = [$a, $b, $c];
    $this->host->pithLocationLoads = [
        $a['id'] => ['cft_offloaded' => 300, 'is_archived' => 0],
        $b['id'] => ['cft_offloaded' => 150.5, 'is_archived' => 0],
        $c['id'] => ['cft_offloaded' => 80, 'is_archived' => 0],
    ];

    $groups = collect($this->host->groupedPithLocations());

    expect($groups->firstWhere('name', 'Alam')['assigned'])->toBe(450.5);
    expect($groups->firstWhere('name', 'Hexagro')['assigned'])->toBe(80.0);
});

it('treats blank and non-numeric entries as zero in the subtotal', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);

    $a = modalLocation($this->teamId, 'alam east', $group->id);
    $a['group_name'] = 'Alam';
    $b = modalLocation($this->teamId, 'alam north', $group->id);
    $b['group_name'] = 'Alam';

    $this->host->pithLocations = [$a, $b];
    $this->host->pithLocationLoads = [
        $a['id'] => ['cft_offloaded' => '', 'is_archived' => 0],
        $b['id'] => ['cft_offloaded' => 200, 'is_archived' => 0],
    ];

    expect(collect($this->host->groupedPithLocations())->firstWhere('name', 'Alam')['assigned'])->toBe(200.0);
});

it('orders groups by display order before name', function () {
    $second = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Aaa', 'display_order' => 5]);
    $first = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Zzz', 'display_order' => 1]);

    $a = modalLocation($this->teamId, 'yard one', $second->id);
    $a['group_name'] = 'Aaa';
    $a['group_display_order'] = 5;
    $b = modalLocation($this->teamId, 'yard two', $first->id);
    $b['group_name'] = 'Zzz';
    $b['group_display_order'] = 1;

    $this->host->pithLocations = [$a, $b];

    expect(collect($this->host->groupedPithLocations())->pluck('name')->all())->toBe(['Zzz', 'Aaa']);
});

it('returns a single ungrouped bucket when the team has no groups', function () {
    $this->host->pithLocations = [
        modalLocation($this->teamId, 'yard one'),
        modalLocation($this->teamId, 'yard two'),
    ];

    $groups = $this->host->groupedPithLocations();

    expect($groups)->toHaveCount(1);
    expect($groups[0]['is_ungrouped'])->toBeTrue();
    expect($groups[0]['locations'])->toHaveCount(2);
});

it('carries group columns through the shared inventory fetch', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam', 'display_order' => 2]);
    modalLocation($this->teamId, 'alam east', $group->id);
    modalLocation($this->teamId, 'stray yard');

    $shiftId = DB::table('pulse_shifts')->insertGetId([
        'team_id' => $this->teamId,
        'name' => 'Shift 1',
        'start_time' => '06:00:00',
        'end_time' => '18:00:00',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $production = DB::table('pulse_summary_pith_productions')->insertGetId([
        'team_id' => $this->teamId,
        'shift_id' => $shiftId,
        'shift_name' => 'Shift 1',
        'shift_date' => now()->toDateString(),
        'start_time' => now()->startOfDay()->addHours(6),
        'end_time' => now()->startOfDay()->addHours(18),
        'cft_produced' => 1000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $data = $this->host->fetchInventoryData($production, $this->teamId);
    $locations = collect($data['pithLocations']);

    $alam = $locations->firstWhere('name', 'alam east');
    expect($alam['group_id'])->toBe($group->id);
    expect($alam['group_name'])->toBe('Alam');
    expect($alam['group_display_order'])->toBe(2);

    expect($locations->firstWhere('name', 'stray yard')['group_name'])->toBeNull();
});
