<?php

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use App\Livewire\CreateTeamForm;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);

        Livewire::component('teams.create-team-form', CreateTeamForm::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        Jetstream::role('owner', 'Owner', [
            '*',
        ])->description('Owners have full control over the team, settings, and billing.');

        Jetstream::role('admin', 'Admin', [
            'read',
            'update',
            'delete',
        ])->description('Admins can add, edit, and delete data, but cannot change settings or add users.');

        Jetstream::role('staff', 'Staff', [
            'read',
            'update',
        ])->description('Staff can only add and edit data, but cannot delete anything.');

        Jetstream::role('reader', 'Reader', [
            'read',
        ])->description('Reader can only view data, cannot add, edit or delete anything.');

        Jetstream::role('supervisor', 'Supervisor', [
            'read',
            'update',
            'delete',
        ])->description('Supervisors can add, edit and delete data, but cannot change settings.');
    }
}
