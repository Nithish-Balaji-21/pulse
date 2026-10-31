<?php

use App\Http\Controllers\Pulse\PithLocationsController;
use App\Livewire\Pulse\ManagePithLocations;
use App\Models\Pulse\PithLocation;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function namingTeam(): int
{
    return TeamFactory::create('Naming Factory '.uniqid());
}

beforeEach(function () {
    $this->teamId = namingTeam();
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('treats numbered variants of the same name as near-duplicates', function (string $a, string $b) {
    expect(PithLocationsController::normalizeNameForComparison($a))
        ->toBe(PithLocationsController::normalizeNameForComparison($b));
})->with([
    ['alam east', 'Alam East 2'],
    ['Alam New1', 'Alam new2'],
    ['east', 'east2'],
    ['new 1', 'New2'],
    ['alam north', 'Alam north 2'],
]);

it('keeps genuinely different names apart', function (string $a, string $b) {
    expect(PithLocationsController::normalizeNameForComparison($a))
        ->not->toBe(PithLocationsController::normalizeNameForComparison($b));
})->with([
    ['alam east', 'alam north'],
    ['Bunker', 'Near Sump'],
    ['hexagro', 'hexagro kalam'],
]);

it('rejects a name longer than the limit', function () {
    $tooLong = str_repeat('a', PithLocationsController::NAME_MAX_LENGTH + 1);

    $response = (new PithLocationsController)->store(request()->merge(['name' => $tooLong]), $this->teamId);

    expect($response->status())->toBe(422);
    expect(PithLocation::where('team_id', $this->teamId)->count())->toBe(0);
});

it('accepts a name exactly at the limit', function () {
    $exact = str_repeat('a', PithLocationsController::NAME_MAX_LENGTH);

    $response = (new PithLocationsController)->store(request()->merge(['name' => $exact]), $this->teamId);

    expect($response->status())->toBe(201);
});

it('still refuses an exact duplicate outright', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'Bunker', 'is_active' => true]);

    $response = (new PithLocationsController)->store(request()->merge(['name' => 'Bunker']), $this->teamId);

    expect($response->status())->toBe(422);
    expect(PithLocation::where('team_id', $this->teamId)->count())->toBe(1);
});

it('warns instead of saving when a near-duplicate name is first submitted', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'alam east', 'is_active' => true]);

    $component = Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'Alam East 2')
        ->call('store');

    expect($component->get('similarNameWarning'))->toContain('alam east');
    expect(PithLocation::where('team_id', $this->teamId)->count())->toBe(1);
});

it('saves the near-duplicate when the warning is confirmed by saving again', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'Bed', 'is_active' => true]);

    $component = Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'Bed 2')
        ->call('store');

    expect(PithLocation::where('team_id', $this->teamId)->count())->toBe(1);

    $component->call('store');

    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'Bed 2')->exists())->toBeTrue();
});

it('re-checks after the name is corrected rather than reusing the confirmation', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'alam east', 'is_active' => true]);

    $component = Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'Alam East 2')
        ->call('store');

    expect($component->get('similarNameWarning'))->not->toBeNull();

    // Correcting the name clears the warning, and a distinct name saves first try.
    $component->set('pithLocationName', 'Near Sump')->call('store');

    expect($component->get('similarNameWarning'))->toBeNull();
    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'Near Sump')->exists())->toBeTrue();
});

it('saves a distinct name without any warning', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'alam east', 'is_active' => true]);

    $component = Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'Bunker')
        ->call('store');

    expect($component->get('similarNameWarning'))->toBeNull();
    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'Bunker')->exists())->toBeTrue();
});

it('does not flag a location against itself when editing', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Bunker', 'is_active' => true]);

    expect(PithLocationsController::findSimilarName('Bunker', $this->teamId, $location->id))->toBeNull();
});

it('ignores near-duplicates belonging to another team', function () {
    $otherTeamId = namingTeam();
    PithLocation::create(['team_id' => $otherTeamId, 'name' => 'alam east', 'is_active' => true]);

    expect(PithLocationsController::findSimilarName('Alam East 2', $this->teamId))->toBeNull();
});
