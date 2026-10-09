<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $institution_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Institution $institution
 * @property-read Collection<int, User> $educators
 * @property-read Collection<int, User> $learners
 * @property-read Collection<int, Assignment> $assignments
 */
#[Table('learner_groups')]
#[Fillable(['institution_id', 'name'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function educators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_educators')->withTimestamps(updatedAt: false);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function learners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_learners')->withTimestamps(updatedAt: false);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
