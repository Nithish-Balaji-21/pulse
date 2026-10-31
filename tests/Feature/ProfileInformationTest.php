<?php

use App\Models\User;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;

test('current profile information is available', function () {
    $this->actingAs($user = User::factory()->create());

    $component = Livewire::test(UpdateProfileInformationForm::class);

    expect($component->state['name'])->toEqual($user->name);
    expect($component->state['email'])->toEqual($user->email);
});

test('profile information can be updated', function () {
    $this->actingAs($user = User::factory()->create(['mobile' => '9876500002']));

    // `mobile` is required by App\Actions\Fortify\UpdateUserProfileInformation.
    Livewire::test(UpdateProfileInformationForm::class)
        ->set('state', [
            'name' => 'Test Name',
            'email' => 'test@example.com',
            'mobile' => '9876500003',
        ])
        ->call('updateProfileInformation');

    expect($user->fresh())
        ->name->toEqual('Test Name')
        ->email->toEqual('test@example.com')
        ->mobile->toEqual('9876500003');
});

test('profile information requires a mobile number', function () {
    $this->actingAs($user = User::factory()->create(['mobile' => '9876500004']));

    Livewire::test(UpdateProfileInformationForm::class)
        ->set('state', ['name' => 'Test Name', 'email' => 'test@example.com', 'mobile' => ''])
        ->call('updateProfileInformation')
        ->assertHasErrors('mobile');

    expect($user->fresh()->name)->not->toEqual('Test Name');
});
