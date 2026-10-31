<?php

use App\Livewire\CreateTeamForm;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Teams (factories) are created by superadmins through App\Livewire\CreateTeamForm,
 * which also provisions the owner user. Jetstream's own CreateTeamForm is unused —
 * this app never published its view.
 */
test('factories can be created with an owner', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create(['is_superadmin' => 1]));

    Livewire::test(CreateTeamForm::class)
        ->set('state.name', 'Test Factory')
        ->set('state.pulse_enabled', true)
        ->set('state.device_id', 90001)
        ->set('state.module', 'pith')
        ->set('state.coordinates', '11.0,77.0')
        ->set('state.owner_name', 'Test Owner')
        ->set('state.owner_email', 'owner@example.com')
        ->set('state.owner_whatsapp', '9876500010')
        ->set('state.owner_password', 'Password1!')
        ->call('createTeam');

    $team = Team::where('name', 'Test Factory')->first();
    $owner = User::where('email', 'owner@example.com')->first();

    expect($team)->not->toBeNull();
    expect($owner)->not->toBeNull();
    expect($owner->ownsTeam($team))->toBeTrue();
    expect($team->pulse)->not->toBeNull();
});

test('factory names must be unique', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create(['is_superadmin' => 1]));

    Team::factory()->create(['name' => 'Existing Factory']);

    Livewire::test(CreateTeamForm::class)
        ->set('state.name', 'Existing Factory')
        ->set('state.pulse_enabled', true)
        ->set('state.device_id', 90002)
        ->set('state.owner_name', 'Test Owner')
        ->set('state.owner_email', 'owner2@example.com')
        ->set('state.owner_whatsapp', '9876500011')
        ->set('state.owner_password', 'Password1!')
        ->call('createTeam')
        ->assertHasErrors('state.name');

    expect(User::where('email', 'owner2@example.com')->exists())->toBeFalse();
});
