<?php

use App\Actions\ChangeDueDate;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('a new instant moves the due date and writes one audit row with the channel', function () {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);

    $result = app(ChangeDueDate::class)->handle($educator, $assignment, CarbonImmutable::parse('2026-10-23 21:59:59'), AuditChannel::Mcp);
    $audit = AuditEntry::query()->sole();

    expect($result->changed)->toBeTrue()
        ->and($result->previousDueAt->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and($result->assignment->due_at->toIso8601ZuluString())->toBe('2026-10-23T21:59:59Z')
        ->and($assignment->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-23T21:59:59Z')
        ->and($audit->action)->toBe(AuditAction::AssignmentDueChanged)
        ->and($audit->channel)->toBe(AuditChannel::Mcp)
        ->and($audit->changes)->toEqual(['due_at' => ['from' => '2026-10-16T21:59:59Z', 'to' => '2026-10-23T21:59:59Z']]);
});

test('the same instant in another time zone changes nothing and writes no audit row', function () {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);

    $result = app(ChangeDueDate::class)->handle($educator, $assignment, CarbonImmutable::parse('2026-10-16T23:59:59+02:00'), AuditChannel::Rest);

    expect($result->changed)->toBeFalse()
        ->and($result->previousDueAt->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('a failed audit write leaves the due date as it was', function () {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);

    DB::beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into `audit_log`')) {
            throw new RuntimeException('The audit write failed.');
        }
    });

    expect(fn () => app(ChangeDueDate::class)->handle($educator, $assignment, CarbonImmutable::parse('2026-10-23 21:59:59'), AuditChannel::Rest))
        ->toThrow(RuntimeException::class, 'The audit write failed.');

    expect($assignment->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z');
});
