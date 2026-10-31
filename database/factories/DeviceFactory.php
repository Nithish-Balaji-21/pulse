<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_name' => $this->faker->word,
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'watts_lower_th' => $this->faker->numberBetween(1, 100),
            'watts_upper_th' => $this->faker->numberBetween(101, 200),
        ];
    }
}
