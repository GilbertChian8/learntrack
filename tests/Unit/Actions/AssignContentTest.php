<?php

use App\Actions\AssignContent;
use App\Enums\AssignmentScope;
use App\Enums\AssignOutcome;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Exceptions\AlreadyAssignedException;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

$friday = fn (): CarbonImmutable => CarbonImmutable::parse('2026-10-16 21:59:59');

test('rule 1: a learner id that is not a current member throws on learner_ids.N and writes nothing', function () use ($friday) {
    [$group, $educator, $content, $priya] = cardiologyGroup();
    $formerMember = learnerIn($group);
    $group->learners()->detach($formerMember);

    try {
        app(AssignContent::class)->handle($educator, $group, $content, $friday(), [$priya->id, $formerMember->id], AuditChannel::Rest);
        throw new RuntimeException('No exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['learner_ids.1' => ["Learner {$formerMember->id} is not a member of this group."]]);
    }

    expect(Assignment::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('rule 2: no active assignment creates one, scope by the presence of learner ids, with its audit row', function () use ($friday) {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();

    $result = app(AssignContent::class)->handle($educator, $group, $content, $friday(), [$tom->id, $priya->id], AuditChannel::Mcp);
    $audit = AuditEntry::query()->sole();

    expect($result->result)->toBe(AssignOutcome::Created)
        ->and($result->assignment->scope)->toBe(AssignmentScope::Learners)
        ->and($result->assignment->content_item_id)->toBe($content->id)
        ->and($result->assignment->created_by)->toBe($educator->id)
        ->and($result->added->pluck('name')->all())->toBe(['Priya Nair', 'Tom Becker'])
        ->and($result->alreadyHadIt->all())->toBe([])
        ->and($audit->action)->toBe(AuditAction::AssignmentCreated)
        ->and($audit->channel)->toBe(AuditChannel::Mcp)
        ->and($audit->subject_id)->toBe($result->assignment->id);
});

test('rule 3: an active assignment with another due date throws with the existing assignment and changes nothing', function () use ($friday) {
    [$group, $educator, $content] = cardiologyGroup();
    $existing = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-23 21:59:59']);

    try {
        app(AssignContent::class)->handle($educator, $group, $content, $friday(), [], AuditChannel::Rest);
        throw new RuntimeException('No exception.');
    } catch (AlreadyAssignedException $exception) {
        expect($exception->assignment->id)->toBe($existing->id)
            ->and($exception->assignment->due_at->toIso8601ZuluString())->toBe('2026-10-23T21:59:59Z');
    }

    expect(Assignment::query()->sole()->due_at->toIso8601ZuluString())->toBe('2026-10-23T21:59:59Z')
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('rule 4: the same due date with scope group changes nothing, whatever learner ids are named', function (bool $withLearnerIds) use ($friday) {
    [$group, $educator, $content, $priya] = cardiologyGroup();
    $existing = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => $friday()]);

    $result = app(AssignContent::class)->handle($educator, $group, $content, $friday(), $withLearnerIds ? [$priya->id] : [], AuditChannel::Rest);

    expect($result->result)->toBe(AssignOutcome::Unchanged)
        ->and($result->assignment->id)->toBe($existing->id)
        ->and($result->added->all())->toBe([])
        ->and($result->alreadyHadIt->pluck('name')->all())->toBe($withLearnerIds ? ['Priya Nair'] : ['Priya Nair', 'Sara Lindqvist', 'Tom Becker'])
        ->and(DB::table('assignment_learners')->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'no learner ids' => false,
    'learner ids' => true,
]);

test('rule 5: the same due date with scope learners adds the learners not yet targeted', function () use ($friday) {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $existing = Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$sara])->create(['due_at' => $friday()]);

    $result = app(AssignContent::class)->handle($educator, $group, $content, $friday(), [$sara->id, $priya->id], AuditChannel::Rest);
    $repeat = app(AssignContent::class)->handle($educator, $group, $content, $friday(), [$sara->id, $priya->id], AuditChannel::Rest);

    expect($result->result)->toBe(AssignOutcome::LearnersAdded)
        ->and($result->added->pluck('id')->all())->toBe([$priya->id])
        ->and($result->alreadyHadIt->pluck('id')->all())->toBe([$sara->id])
        ->and($repeat->result)->toBe(AssignOutcome::Unchanged)
        ->and($repeat->alreadyHadIt->pluck('name')->all())->toBe(['Priya Nair', 'Sara Lindqvist'])
        ->and($existing->fresh()?->scope)->toBe(AssignmentScope::Learners)
        ->and(AuditEntry::query()->sole()->changes)->toBe(['learner_ids' => [$priya->id]]);
});

test('rule 5: the same due date with scope learners and no learner ids widens to the group and deletes nothing', function () use ($friday) {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    $existing = Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$sara])->create(['due_at' => $friday()]);
    Progress::factory()->for($existing)->for($sara, 'user')->create();

    $result = app(AssignContent::class)->handle($educator, $group, $content, $friday(), [], AuditChannel::Rest);

    expect($result->result)->toBe(AssignOutcome::LearnersAdded)
        ->and($result->assignment->scope)->toBe(AssignmentScope::Group)
        ->and($result->added->pluck('name')->all())->toBe(['Priya Nair', 'Tom Becker'])
        ->and($result->alreadyHadIt->pluck('name')->all())->toBe(['Sara Lindqvist'])
        ->and(DB::table('assignment_learners')->where('assignment_id', $existing->id)->pluck('user_id')->all())->toBe([$sara->id])
        ->and(Progress::query()->count())->toBe(1)
        ->and(AuditEntry::query()->sole()->changes)->toEqual([
            'learner_ids' => collect([$priya->id, $tom->id])->sort()->values()->all(),
            'scope' => ['from' => 'learners', 'to' => 'group'],
        ]);
});

test('a failed audit write leaves no change behind', function (Closure $arrange) use ($friday) {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $arrange($group, $content, $sara);
    $before = [Assignment::query()->get()->toArray(), DB::table('assignment_learners')->get()->all()];

    DB::beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into `audit_log`')) {
            throw new RuntimeException('The audit write failed.');
        }
    });

    expect(fn () => app(AssignContent::class)->handle($educator, $group, $content, $friday(), [$priya->id], AuditChannel::Rest))
        ->toThrow(RuntimeException::class, 'The audit write failed.');

    expect([Assignment::query()->get()->toArray(), DB::table('assignment_learners')->get()->all()])->toEqual($before);
})->with([
    'create' => fn () => null,
    'add learners' => fn (Group $group, ContentItem $content, User $sara) => Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$sara])->create(['due_at' => '2026-10-16 21:59:59']),
]);
