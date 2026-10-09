<?php

namespace App\Models;

use App\Enums\AssignmentScope;
use Carbon\CarbonImmutable;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dates are written with microseconds, so two removals of the same content
 * in the same second still get distinct removed_key values.
 *
 * @property int $id
 * @property int $group_id
 * @property int $content_item_id
 * @property CarbonImmutable $due_at
 * @property AssignmentScope $scope
 * @property int $created_by
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Group $group
 * @property-read ContentItem $contentItem
 * @property-read User $creator
 * @property-read Collection<int, User> $learners
 * @property-read Collection<int, Progress> $progress
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[Fillable(['group_id', 'content_item_id', 'due_at', 'scope', 'created_by', 'removed_at'])]
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'scope' => AssignmentScope::class,
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Only assignments that have not been removed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('removed_at'));
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /**
     * The educator who made the assignment.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The learners a subset assignment targets. Empty when the scope is "group".
     *
     * @return BelongsToMany<User, $this>
     */
    public function learners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'assignment_learners')->withTimestamps(updatedAt: false);
    }

    /**
     * @return HasMany<Progress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(Progress::class);
    }
}
