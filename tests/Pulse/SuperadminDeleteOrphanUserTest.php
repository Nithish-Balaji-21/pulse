<?php

use App\Livewire\SuperadminUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->superadmin = User::factory()->create(['is_superadmin' => 1]);
    $this->actingAs($this->superadmin);
});

it('deletes a user with no team and no records', function () {
    $orphan = User::factory()->create();
    DB::table('user_device_tokens')->insert([
        'user_id' => $orphan->id,
        'device_id' => 'dev-'.uniqid(),
        'device_platform' => 'android',
        'fcm_token' => 'tok-'.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($orphan->isDeletableOrphan())->toBeTrue();

    Livewire::test(SuperadminUsers::class)
        ->call('confirmDeletion', $orphan->id)
        ->assertSet('showDeleteConfirmationDialog', true)
        ->call('deleteUser')
        ->assertSet('showDeleteConfirmationDialog', false);

    expect(User::find($orphan->id))->toBeNull();
    expect(DB::table('user_device_tokens')->where('user_id', $orphan->id)->exists())->toBeFalse();
});

it('refuses to delete a user who belongs to a team', function () {
    $teamId = TeamFactory::create('Orphan Test '.uniqid());
    $member = User::factory()->create();
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $member->id,
        'role' => 'staff',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($member->isDeletableOrphan())->toBeFalse();

    Livewire::test(SuperadminUsers::class)
        ->call('confirmDeletion', $member->id)
        ->assertSet('showDeleteConfirmationDialog', false);

    expect(User::find($member->id))->not->toBeNull();
});

it('refuses to delete a teamless user who has records', function () {
    $teamId = TeamFactory::create('Orphan Records Test '.uniqid());
    $author = User::factory()->create();
    DB::table('staffs')->insert([
        'team_id' => $teamId,
        'staff_name' => 'Recorded Staff',
        'salary_per_hr' => 100,
        'is_active' => 1,
        'created_by' => $author->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($author->hasAssociatedRecords())->toBeTrue();
    expect($author->isDeletableOrphan())->toBeFalse();

    Livewire::test(SuperadminUsers::class)
        ->call('confirmDeletion', $author->id)
        ->assertSet('showDeleteConfirmationDialog', false);

    expect(User::find($author->id))->not->toBeNull();
});

it('never deletes a superadmin', function () {
    $other = User::factory()->create(['is_superadmin' => 1]);

    expect($other->isDeletableOrphan())->toBeFalse();
});

it('forbids non-superadmins from deleting', function () {
    $orphan = User::factory()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test(SuperadminUsers::class)
        ->set('confirmingUserId', $orphan->id)
        ->call('deleteUser')
        ->assertForbidden();

    expect(User::find($orphan->id))->not->toBeNull();
});
