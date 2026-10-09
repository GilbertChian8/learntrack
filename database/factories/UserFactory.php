<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Learner,
            'remember_token' => Str::random(10),
        ];
    }

    public function educator(): static
    {
        return $this->state(['role' => Role::Educator]);
    }

    public function learner(): static
    {
        return $this->state(['role' => Role::Learner]);
    }
}
