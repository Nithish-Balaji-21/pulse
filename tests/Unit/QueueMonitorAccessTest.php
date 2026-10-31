<?php

use App\Http\Middleware\ResolveActiveTeam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Pulse\Support\TeamFactory;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->withoutMiddleware(ResolveActiveTeam::class);
    config(['session.driver' => 'array']);
});

it('requires login for the queue dashboard', function () {
    $this->get('/queue-monitor')->assertRedirect('/login');
    $this->get('/monitor/guide/images/horizon')->assertRedirect('/login');
});

it('rejects ordinary users from the dashboard and its data endpoints', function () {
    $user = new User;
    $user->forceFill(['id' => 999999, 'is_superadmin' => false]);
    $this->actingAs($user);

    $this->get('/queue-monitor')->assertForbidden();
    $this->getJson('/queue-monitor/jobs')->assertForbidden();
    $this->get('/monitor/guide/images/horizon')->assertForbidden();
});

it('allows superadmins to view the dashboard and job data', function () {
    $user = new User;
    $user->forceFill(['id' => 999999, 'is_superadmin' => true, 'is_active' => true]);
    $this->actingAs($user);

    $this->get('/queue-monitor')->assertOk();
    $this->getJson('/queue-monitor/jobs')->assertOk();
    $this->getJson('/queue-monitor/metrics')->assertOk();
});

it('does not expose the optional public API in the local trial', function () {
    $this->getJson('/api/queue-monitor/jobs')->assertNotFound();
});

it('integrates the monitoring hub into the application navigation for superadmins', function () {
    $teamId = TeamFactory::create('Queue Monitor Test Factory '.uniqid());
    $team = Team::query()->findOrFail($teamId);
    $user = new User;
    $user->forceFill(['id' => 999999, 'is_superadmin' => 1, 'is_active' => 1, 'current_team_id' => $team->id]);
    $this->actingAs($user);
    $this->get(route('superadmin.monitoring', ['team' => $team->id]))
        ->assertOk()->assertSee('DevOps Tools', false)
        ->assertDontSee('Back to application', false)
        ->assertDontSee('Check jobs across all factories', false)
        ->assertDontSee('Monitoring guide</h2>', false);
    $this->get(route('superadmin.monitoring', ['team' => $team->id, 'tab' => 'queue']))
        ->assertOk()->assertSee(url('/queue-monitor'), false);
});

it('rejects disabled superadmins across monitoring pages', function () {
    $user = new User;
    $user->forceFill(['id' => 999999, 'is_superadmin' => true, 'is_active' => false]);
    $this->actingAs($user);
    foreach (['/queue-monitor', '/monitor/guide/images/horizon'] as $path) {
        $this->get($path)->assertForbidden();
    }
});

it('prevents authenticated monitoring pages from being cached', function () {
    $user = new User;
    $user->forceFill(['id' => 999999, 'is_superadmin' => true, 'is_active' => true]);
    $this->actingAs($user);
    foreach (['/queue-monitor', '/monitor/guide/images/horizon'] as $path) {
        $response = $this->get($path)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});
