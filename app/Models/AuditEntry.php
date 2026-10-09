<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use Carbon\CarbonImmutable;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per change. Holds ids and values, never names or emails.
 *
 * @property int $id
 * @property int $actor_id
 * @property AuditChannel $channel
 * @property AuditAction $action
 * @property int|null $group_id
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed> $changes
 * @property CarbonImmutable $created_at
 * @property-read User $actor
 */
#[Table('audit_log', dateFormat: 'Y-m-d H:i:s.u')]
#[Fillable(['actor_id', 'channel', 'action', 'group_id', 'subject_type', 'subject_id', 'changes'])]
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => AuditChannel::class,
            'action' => AuditAction::class,
            'changes' => 'array',
        ];
    }

    /**
     * The educator who made the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
