<?php

namespace Database\Factories;

use App\Enums\ProgressStatus;
use App\Models\Assignment;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Progress>
 */
class ProgressFactory extends Factory
{
    /**
     * Define the model's default state: started, not completed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'user_id' => User::factory()->learner(),
            'status' => ProgressStatus::InProgress,
            'score' => null,
            'started_at' => now()->subDays(fake()->numberBetween(1, 7))->startOfSecond(),
            'completed_at' => null,
        ];
    }

    /**
     * Completed, with a score for a question set or null for an article.
     */
    public function completed(?int $score = null): static
    {
        return $this->state(fn () => [
            'status' => ProgressStatus::Completed,
            'score' => $score,
            'completed_at' => now()->startOfSecond(),
        ]);
    }
}
