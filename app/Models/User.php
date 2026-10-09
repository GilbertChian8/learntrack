<?php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property int $institution_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Institution $institution
 * @property-read Collection<int, Group> $educatorOf
 * @property-read Collection<int, Group> $memberOf
 * @property-read Collection<int, Progress> $progress
 */
#[Fillable(['institution_id', 'name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * The groups this educator teaches.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function educatorOf(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_educators')->withTimestamps(updatedAt: false);
    }

    /**
     * The groups this learner is a member of.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function memberOf(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_learners')->withTimestamps(updatedAt: false);
    }

    /**
     * @return HasMany<Progress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(Progress::class);
    }
}
