<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->randomElement(ContentItemFactory::TOPICS).' Group '.strtoupper(fake()->randomLetter()),
        ];
    }
}
