<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    /**
     * Define the model's default state: a group.created row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->educator(),
            'channel' => AuditChannel::Rest,
            'action' => AuditAction::GroupCreated,
            'group_id' => Group::factory(),
            'subject_type' => 'group',
            'subject_id' => fn (array $attributes) => $attributes['group_id'],
            'changes' => fn (array $attributes) => [
                'name' => Group::query()->whereKey($attributes['group_id'])->value('name'),
            ],
        ];
    }
}
