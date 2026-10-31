<?php

namespace App\Actions\Jetstream;

use App\Mail\NewUserCredentialsMail;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     */
    public function add(User $user, Team $team, string $email, ?string $role = null, ?string $mobile = null, ?string $name = null): void
    {
        if ($role === 'owner' && $team->users()->wherePivot('role', 'owner')->exists()) {
            throw ValidationException::withMessages([
                'role' => ['This team already has an owner.'],
            ]);
        }

        Gate::forUser($user)->authorize('addTeamMember', $team);

        // Check if user already exists
        $existingUser = User::where('email', $email)->where('mobile', $mobile)->first();

        if ($existingUser) {

            AddingTeamMember::dispatch($team, $existingUser);
            // Check if user is already in the team
            if ($team->users()->where('user_id', $existingUser->id)->exists()) {
                return;
            }
            $team->users()->attach($existingUser, ['role' => $role]);
            $existingUser->update(['current_team_id' => $team->id]);

            TeamMemberAdded::dispatch($team, $existingUser);
        } else {
            $initialPassword = strtolower(str_replace(' ', '', $team->name));
            // Create a new user and add to the team
            $newUser = User::create([
                'name' => $name ?? $email,
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make($initialPassword),
                'current_team_id' => $team->id,
            ]);

            AddingTeamMember::dispatch($team, $newUser);
            $team->users()->attach($newUser, ['role' => $role]);
            TeamMemberAdded::dispatch($team, $newUser);

            if (app()->environment('local') && (int) env('APP_SEND_EMAIL', 0) !== 1) {
                Log::info("Skipping email sending in local environment for user: {$newUser->email}");
            } else {
                Mail::to($newUser->email)->send(new NewUserCredentialsMail($newUser, $initialPassword, $team->name));
            }
        }

    }

    /**
     * Validate the add member operation.
     */
    protected function validate(Team $team, string $email, ?string $role): void
    {
        Validator::make(
            [
                'email' => $email,
                'role' => $role,
            ],
            $this->rules() // Use the defined rules for validation
        )->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for adding a team member.
     *
     * @return array<string, Rule|array|string>
     */
    protected function rules(): array
    {
        return array_filter([
            'email' => ['required', 'email'],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role]
                            : null,
        ]);
    }

    /**
     * Ensure that the user is not already on the team.
     */
    protected function ensureUserIsNotAlreadyOnTeam(Team $team, string $email): Closure
    {
        return function ($validator) use ($team, $email) {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the factory.')
            );
        };
    }
}
