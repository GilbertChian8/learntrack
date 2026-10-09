<?php

namespace Database\Factories;

use App\Enums\AssignmentScope;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    /**
     * Define the model's default state: a group-wide assignment due in the
     * future, made by an educator of the group's institution.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'content_item_id' => ContentItem::factory(),
            'due_at' => now()->addDays(fake()->numberBetween(1, 30))->startOfSecond(),
            'scope' => AssignmentScope::Group,
            'created_by' => fn (array $attributes) => User::factory()->educator()->state([
                'institution_id' => Group::query()->whereKey($attributes['group_id'])->value('institution_id'),
            ]),
            'removed_at' => null,
        ];
    }

    /**
     * A subset assignment that targets only the given learners. It does not
     * make them members of the group.
     *
     * @param  array<int, User|int>  $learners
     */
    public function forLearners(array $learners): static
    {
        return $this->state(['scope' => AssignmentScope::Learners])
            ->afterCreating(fn (Assignment $assignment) => $assignment->learners()->attach($learners));
    }

    public function removed(): static
    {
        return $this->state(fn () => ['removed_at' => now()]);
    }
}
