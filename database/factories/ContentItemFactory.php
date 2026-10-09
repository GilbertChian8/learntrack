<?php

namespace Database\Factories;

use App\Enums\ContentType;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    public const TOPICS = ['Cardiology', 'Pulmonology', 'Nephrology', 'Neurology', 'Endocrinology', 'Pediatrics'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::title(fake()->words(3, true)),
            'topic' => fake()->randomElement(self::TOPICS),
            'type' => fake()->randomElement(ContentType::cases()),
            'estimated_minutes' => fake()->numberBetween(10, 45),
        ];
    }
}
