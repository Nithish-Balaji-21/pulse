<?php

use App\Livewire\Pulse\SumpCalculator;
use Livewire\Livewire;

it('renders an independent sump calculator with the two sump shapes', function () {
    Livewire::test(SumpCalculator::class)
        ->assertSee('Sump Calculator')
        ->assertSee('Rectangular')
        ->assertSee('Circular')
        ->assertSee('Estimated capacity')
        ->assertDontSee('ft.in notation')
        ->assertDontSee('Calculate');
});

it('renders a labelled Sump chip that controls its popover', function () {
    $component = Livewire::test(SumpCalculator::class);
    $popoverId = 'sump-popover-'.$component->get('instanceKey');

    $component
        ->assertSeeHtml('aria-controls="'.$popoverId.'"')
        ->assertSeeHtml('id="'.$popoverId.'"')
        ->assertSeeHtml('title="Sump calculator"')
        ->assertSeeHtml('<span class="max-lg:sr-only">Sump</span>');
});

it('calculates rectangular capacity using feet-inch notation', function () {
    Livewire::test(SumpCalculator::class)
        ->set('dimensions.length', '25.5')
        ->set('dimensions.width', '7.6')
        ->set('dimensions.depth', '8.11')
        ->assertSee('1,699.74 CFT');
});

it('updates rectangular capacity as each dimension is entered', function () {
    Livewire::test(SumpCalculator::class)
        ->set('dimensions.length', '10')
        ->set('dimensions.width', '8')
        ->assertSee('— CFT')
        ->set('dimensions.depth', '6')
        ->assertSee('480.00 CFT');
});

it('calculates a two digit inch entry by carrying excess inches into feet', function () {
    Livewire::test(SumpCalculator::class)
        ->set('dimensions.length', '10.6')
        ->set('dimensions.width', '8.77')
        ->set('dimensions.depth', '6')
        ->assertSee('908.25 CFT');
});

it('calculates circular capacity from diameter and depth', function () {
    Livewire::test(SumpCalculator::class)
        ->set('shape', 'circular')
        ->set('dimensions.length', '10')
        ->set('dimensions.depth', '6')
        ->assertSee('471.24 CFT');
});

it('rejects invalid dimensions', function () {
    Livewire::test(SumpCalculator::class)
        ->set('dimensions.length', '10.123')
        ->set('dimensions.width', '8')
        ->set('dimensions.depth', '6')
        ->assertSee('— CFT');
});
