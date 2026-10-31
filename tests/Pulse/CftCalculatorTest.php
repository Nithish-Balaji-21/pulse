<?php

use App\Livewire\Pulse\CftCalculator;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('CFT Calculator '.uniqid());
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('renders as a compact Husk calculator popover', function () {
    Livewire::test(CftCalculator::class)
        ->assertSee('Husk Calculator')
        ->assertSee('Volume')
        ->assertDontSee('Volume 2')
        ->assertDontSee('Combined total')
        ->assertDontSee('ft.in notation')
        ->assertDontSee('Feet and inches are supported');
});

it('renders a labelled Husk chip that controls its popover', function () {
    $component = Livewire::test(CftCalculator::class);
    $popoverId = 'cft-popover-'.$component->get('instanceKey');

    $component
        ->assertSeeHtml('aria-controls="'.$popoverId.'"')
        ->assertSeeHtml('id="'.$popoverId.'"')
        ->assertSeeHtml('title="Husk calculator"')
        ->assertSeeHtml('<span class="max-lg:sr-only">Husk</span>');
});

it('calculates one volume from feet and feet-inch notation', function () {
    Livewire::test(CftCalculator::class)
        ->set('dimensions.length', '25.5')
        ->set('dimensions.width', '7.6')
        ->set('dimensions.height', '8.11')
        ->assertSee('1,699.74')
        ->set('dimensions.length', '10.6')
        ->set('dimensions.width', '8')
        ->set('dimensions.height', '6')
        ->assertSee('504.00');
});

it('accepts up to two inch digits and carries excess inches into feet', function () {
    Livewire::test(CftCalculator::class)
        ->set('dimensions.length', '10.6')
        ->set('dimensions.width', '8.77')
        ->set('dimensions.height', '6')
        ->assertSee('908.25');
});

it('does not show a result until all dimensions are valid and entered', function () {
    Livewire::test(CftCalculator::class)
        ->set('dimensions.length', '10')
        ->set('dimensions.width', '-2')
        ->set('dimensions.height', '6')
        ->assertSee('— CFT');
});

it('rejects a feet-inch value with twelve inches in the inch portion', function () {
    Livewire::test(CftCalculator::class)
        ->set('dimensions.length', '10.123')
        ->set('dimensions.width', '8')
        ->set('dimensions.height', '6')
        ->assertSee('— CFT');
});
