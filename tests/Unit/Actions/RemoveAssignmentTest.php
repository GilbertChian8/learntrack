<?php

use App\Actions\RemoveAssignment;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\Progress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:10:02');
});

test('sets removed_at and removed_key in one write with one audit row, keeps progress, and does nothing the second time', function () {
    [$group, $educator, , $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create();
    $progress = Progress::factory()->for($assignment)->for($priya, 'user')->completed()->create();

    $first = app(RemoveAssignment::class)->handle($educator, $assignment, AuditChannel::Mcp);
    $second = app(RemoveAssignment::class)->handle($educator, $assignment->fresh() ?? $assignment, AuditChannel::Mcp);
    $audit = AuditEntry::query()->sole();

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($assignment->fresh()?->removed_key)->toBe($assignment->id)
        ->and($assignment->fresh()?->removed_at?->toIso8601ZuluString())->toBe('2026-10-09T12:10:02Z')
        ->and(Progress::query()->whereKey($progress->id)->exists())->toBeTrue()
        ->and($audit->action)->toBe(AuditAction::AssignmentRemoved)
        ->and($audit->channel)->toBe(AuditChannel::Mcp)
        ->and($audit->changes)->toBe(['removed_at' => '2026-10-09T12:10:02Z']);
});

test('a failed audit write leaves the assignment active', function () {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create();

    DB::beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into `audit_log`')) {
            throw new RuntimeException('The audit write failed.');
        }
    });

    expect(fn () => app(RemoveAssignment::class)->handle($educator, $assignment, AuditChannel::Rest))
        ->toThrow(RuntimeException::class, 'The audit write failed.');

    expect($assignment->fresh()?->removed_at)->toBeNull()
        ->and($assignment->fresh()?->removed_key)->toBe(0);
});
