<?php

namespace App\Models;

use App\Enums\ProgressStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner's progress on one assignment. "Not started" is the absence of a row.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $user_id
 * @property ProgressStatus $status
 * @property int|null $score
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Assignment $assignment
 * @property-read User $user
 */
#[Table('progress')]
#[Fillable(['assignment_id', 'user_id', 'status', 'score', 'started_at', 'completed_at'])]
class Progress extends Model
{
    /** @use HasFactory<ProgressFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProgressStatus::class,
            'score' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * The learner.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
