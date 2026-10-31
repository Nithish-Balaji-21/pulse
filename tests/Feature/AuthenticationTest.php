<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withPersonalTeam()->create();

    // The login form posts a `username` field, which FortifyServiceProvider
    // resolves against either `users.email` or `users.mobile`.
    $response = $this->post('/login', [
        'username' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(config('fortify.home'));
});

test('users can authenticate using their mobile number', function () {
    $user = User::factory()->withPersonalTeam()->create(['mobile' => '9876500001']);

    $this->post('/login', [
        'username' => $user->mobile,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->post('/login', [
        'username' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('deactivated users cannot authenticate', function () {
    $user = User::factory()->withPersonalTeam()->create(['is_active' => 0]);

    $this->post('/login', [
        'username' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('users without a factory cannot authenticate', function () {
    $user = User::factory()->create(['current_team_id' => null]);

    $this->post('/login', [
        'username' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('already authenticated users are redirected away from guest-only Fortify routes without error', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $user->update(['current_team_id' => $user->ownedTeams()->first()->id]);

    // GET /login is overridden by the app's own route (routes/web.php), but other
    // Fortify guest routes (e.g. forgot-password) still use the framework's default
    // RedirectIfAuthenticated, which previously crashed on the team-scoped dashboard route.
    $response = $this->actingAs($user)->get('/forgot-password');

    $response->assertRedirect("/{$user->current_team_id}/dashboard");
});
