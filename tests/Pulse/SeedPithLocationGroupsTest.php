<?php

use App\Console\Commands\Pulse\SeedPithLocationGroups;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use Tests\Pulse\Support\TeamFactory;

function seedTeam(): int
{
    return TeamFactory::create('Seed Factory '.uniqid());
}

function seedLocation(int $teamId, string $name, ?int $groupId = null): PithLocation
{
    return PithLocation::create([
        'team_id' => $teamId,
        'group_id' => $groupId,
        'name' => $name,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->teamId = seedTeam();
    $this->matcher = new SeedPithLocationGroups;
});

it('maps every Alam Cocos location to the reviewed group', function (string $name, ?string $expected) {
    expect($this->matcher->resolveGroupName($name))->toBe($expected);
})->with([
    // Alam — 11
    ['Alam Bunker', 'Alam'],
    ['alam east', 'Alam'],
    ['alam east - old', 'Alam'],
    ['alam east 2', 'Alam'],
    ['Alam Near Sump', 'Alam'],
    ['Alam New1', 'Alam'],
    ['Alam new2', 'Alam'],
    ['alam north', 'Alam'],
    ['Alam north 2', 'Alam'],
    ['alam north west', 'Alam'],
    ['Location alam', 'Alam'],
    // Hexagro — 3
    ['hexagro', 'Hexagro'],
    ['Hexagro Bunker', 'Hexagro'],
    ['hexagro kalam', 'Hexagro'],
    // Doctor Land — 3
    ['doctor land 1', 'Doctor Land'],
    ['doctor land 2', 'Doctor Land'],
    ['dr land tarpaulin', 'Doctor Land'],
    // Kannappa Gounder — 3
    ['kannappa gounder east', 'Kannappa Gounder'],
    ['kannappa gounder west', 'Kannappa Gounder'],
    ['kannappa gounder to alam pith drying', 'Kannappa Gounder'],
    // Loganatha Gounder — 2
    ['Loganatha Gounder', 'Loganatha Gounder'],
    ['Loganatha goundar', 'Loganatha Gounder'],
    // Singles
    ['karanampettai', 'Karanampettai'],
    ['Hen forms', 'Hen Forms'],
    ['hari 2', 'Hari'],
]);

it('anchors prefixes on a word boundary so partial words do not match', function () {
    expect($this->matcher->resolveGroupName('Alamu Yard'))->toBeNull();
    expect($this->matcher->resolveGroupName('Harihar Field'))->toBeNull();
    expect($this->matcher->resolveGroupName('Karanampettai Extension'))->toBe('Karanampettai');
});

it('does not capture hexagro kalam into the alam group', function () {
    // The trap a naive substring match would fall into: "k-alam".
    expect($this->matcher->resolveGroupName('hexagro kalam'))->toBe('Hexagro');
});

it('leaves an unrecognised name unmatched rather than guessing', function () {
    expect($this->matcher->resolveGroupName('Some New Yard'))->toBeNull();
});

it('creates the groups and assigns ungrouped locations', function () {
    seedLocation($this->teamId, 'alam east');
    seedLocation($this->teamId, 'Alam Bunker');
    seedLocation($this->teamId, 'hexagro kalam');

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->expectsConfirmation('Create 2 group(s) and assign 3 location(s)?', 'yes')
        ->assertExitCode(0);

    $alam = PithLocationGroup::where('team_id', $this->teamId)->where('name', 'Alam')->first();
    $hexagro = PithLocationGroup::where('team_id', $this->teamId)->where('name', 'Hexagro')->first();

    expect($alam)->not->toBeNull();
    expect($alam->display_order)->toBe(1);
    expect($alam->pithLocations()->count())->toBe(2);
    expect($hexagro->pithLocations()->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    seedLocation($this->teamId, 'alam east');

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId, '--dry-run' => true])
        ->assertExitCode(0);

    expect(PithLocationGroup::where('team_id', $this->teamId)->count())->toBe(0);
    expect(PithLocation::where('team_id', $this->teamId)->whereNotNull('group_id')->count())->toBe(0);
});

it('writes nothing when the confirmation is declined', function () {
    seedLocation($this->teamId, 'alam east');

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->expectsConfirmation('Create 1 group(s) and assign 1 location(s)?', 'no')
        ->assertExitCode(0);

    expect(PithLocationGroup::where('team_id', $this->teamId)->count())->toBe(0);
});

it('never overwrites a location that already has a group', function () {
    $manual = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Manual Choice']);
    $location = seedLocation($this->teamId, 'alam east', $manual->id);

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->assertExitCode(0);

    expect($location->fresh()->group_id)->toBe($manual->id);
    expect(PithLocationGroup::where('team_id', $this->teamId)->where('name', 'Alam')->exists())->toBeFalse();
});

it('is idempotent — a second run assigns nothing new', function () {
    seedLocation($this->teamId, 'alam east');
    seedLocation($this->teamId, 'alam north');

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->expectsConfirmation('Create 1 group(s) and assign 2 location(s)?', 'yes')
        ->assertExitCode(0);

    $groupCount = PithLocationGroup::where('team_id', $this->teamId)->count();

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->assertExitCode(0);

    expect(PithLocationGroup::where('team_id', $this->teamId)->count())->toBe($groupCount);
});

it('reuses an existing group of the same name instead of duplicating it', function () {
    PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam', 'display_order' => 9]);
    seedLocation($this->teamId, 'alam east');

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->expectsConfirmation('Create 1 group(s) and assign 1 location(s)?', 'yes')
        ->assertExitCode(0);

    $groups = PithLocationGroup::where('team_id', $this->teamId)->where('name', 'Alam')->get();

    expect($groups)->toHaveCount(1);
    // The existing group keeps its own display order.
    expect($groups->first()->display_order)->toBe(9);
});

it('leaves other teams untouched', function () {
    $otherTeamId = seedTeam();
    seedLocation($otherTeamId, 'alam east');
    seedLocation($this->teamId, 'alam north');

    $this->artisan('pith:seed-location-groups', ['--team' => $this->teamId])
        ->expectsConfirmation('Create 1 group(s) and assign 1 location(s)?', 'yes')
        ->assertExitCode(0);

    expect(PithLocationGroup::where('team_id', $otherTeamId)->count())->toBe(0);
    expect(PithLocation::where('team_id', $otherTeamId)->whereNotNull('group_id')->count())->toBe(0);
});

it('fails cleanly for a team that does not exist', function () {
    $this->artisan('pith:seed-location-groups', ['--team' => 999999])
        ->assertExitCode(1);
});
