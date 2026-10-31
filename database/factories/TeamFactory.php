<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
        ];
    }

    /**
     * Attach the given user to the team as its owner.
     *
     * Ownership lives on the `team_user` pivot (`role` = owner); `teams.user_id`
     * was dropped by 2026_07_27_130000_drop_user_id_from_teams.
     */
    public function ownedBy(User $user): static
    {
        return $this->afterCreating(
            fn (Team $team) => $team->users()->attach($user, ['role' => 'owner'])
        );
    }
}
