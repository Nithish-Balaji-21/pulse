<?php

use App\Models\User;

it('redirects guests from the root route to login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

it('redirects authenticated users from the root route to their dashboard', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertRedirect('/dashboard');
});
