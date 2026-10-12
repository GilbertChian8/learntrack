<?php

use App\Enums\ContentType;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Progress;
use App\Models\User;
use App\Queries\GroupProgress;
use App\Queries\Results\AssignmentStats;
use App\Queries\Results\AssignmentStatus;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

$query = <<<'GRAPHQL'
    query ($groupId: ID!) {
      groupProgress(groupId: $groupId) {
        group { id name institution }
        generatedAt
        assignments { id title topic type dueAt scope targetedCount completedCount completionRate averageScore }
        learners {
          learner { id name }
          behind
          reasons
          averageScore
          counts { notStarted inProgress completed overdue }
          statuses { assignmentId status score completedAt }
        }
      }
    }
    GRAPHQL;

$askFor = fn (int|string $groupId): TestResponse => postJson('/graphql', ['query' => $query, 'variables' => ['groupId' => (string) $groupId]]);

test('the full query answers the contract shape, in the contract order', function () use ($askFor) {
    $institution = Institution::factory()->create(['name' => 'Northside Medical School', 'timezone' => 'Europe/Berlin']);
    $group = Group::factory()->for($institution)->create(['name' => 'Cardiology Group A']);
    $educator = educatorOf($group);

    $member = function (string $name) use ($group): User {
        $learner = User::factory()->learner()->create(['institution_id' => $group->institution_id, 'name' => $name]);
        $group->learners()->attach($learner);

        return $learner;
    };
    $assign = function (string $title, ContentType $type, string $dueAt, array $learners = []) use ($group, $educator): Assignment {
        $factory = Assignment::factory()->for($group)
            ->for(ContentItem::factory()->state(['title' => $title, 'topic' => 'Cardiology', 'type' => $type]), 'contentItem');

        return ($learners === [] ? $factory : $factory->forLearners($learners))
            ->create(['due_at' => $dueAt, 'created_by' => $educator->id]);
    };
    $complete = fn (Assignment $assignment, User $learner, ?int $score, string $at) => Progress::factory()
        ->for($assignment)->for($learner, 'user')->completed($score)
        ->create(['started_at' => $at, 'completed_at' => $at]);

    $tom = $member('Tom Becker');
    $sara = $member('Sara Lindqvist');
    $otherSara = $member('Sara Lindqvist');
    $leaver = $member('Lea Vogel');

    $ecg = $assign('ECG Basics', ContentType::Article, '2026-10-02 21:59:59');
    $heartFailure = $assign('Heart Failure Question Set', ContentType::QuestionSet, '2026-10-16 21:59:59', [$sara, $tom]);
    $arrhythmia = $assign('Arrhythmia Question Set', ContentType::QuestionSet, '2026-10-16 21:59:59');
    $lipids = $assign('Lipid Disorders Question Set', ContentType::QuestionSet, '2026-10-20 21:59:59', [$leaver]);
    $group->learners()->detach($leaver);

    $complete($ecg, $sara, null, '2026-10-01 10:00:00');
    $complete($heartFailure, $sara, 72, '2026-10-08 09:30:00');
    Progress::factory()->for($arrhythmia)->for($tom, 'user')->create(['started_at' => '2026-10-07 08:00:00']);

    actingAsEducator($educator);

    $askFor($group->id)
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'groupProgress' => [
                    'group' => ['id' => (string) $group->id, 'name' => 'Cardiology Group A', 'institution' => 'Northside Medical School'],
                    'generatedAt' => '2026-10-09T14:20:00+02:00',
                    'assignments' => [
                        ['id' => (string) $ecg->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'ARTICLE', 'dueAt' => '2026-10-02T23:59:59+02:00', 'scope' => 'GROUP', 'targetedCount' => 3, 'completedCount' => 1, 'completionRate' => 0.33, 'averageScore' => null],
                        ['id' => (string) $heartFailure->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'QUESTION_SET', 'dueAt' => '2026-10-16T23:59:59+02:00', 'scope' => 'LEARNERS', 'targetedCount' => 2, 'completedCount' => 1, 'completionRate' => 0.5, 'averageScore' => 72.0],
                        ['id' => (string) $arrhythmia->id, 'title' => 'Arrhythmia Question Set', 'topic' => 'Cardiology', 'type' => 'QUESTION_SET', 'dueAt' => '2026-10-16T23:59:59+02:00', 'scope' => 'GROUP', 'targetedCount' => 3, 'completedCount' => 0, 'completionRate' => 0.0, 'averageScore' => null],
                        ['id' => (string) $lipids->id, 'title' => 'Lipid Disorders Question Set', 'topic' => 'Cardiology', 'type' => 'QUESTION_SET', 'dueAt' => '2026-10-20T23:59:59+02:00', 'scope' => 'LEARNERS', 'targetedCount' => 0, 'completedCount' => 0, 'completionRate' => 0.0, 'averageScore' => null],
                    ],
                    'learners' => [
                        [
                            'learner' => ['id' => (string) $sara->id, 'name' => 'Sara Lindqvist'],
                            'behind' => false,
                            'reasons' => [],
                            'averageScore' => 72.0,
                            'counts' => ['notStarted' => 1, 'inProgress' => 0, 'completed' => 2, 'overdue' => 0],
                            'statuses' => [
                                ['assignmentId' => (string) $ecg->id, 'status' => 'COMPLETED', 'score' => null, 'completedAt' => '2026-10-01T12:00:00+02:00'],
                                ['assignmentId' => (string) $heartFailure->id, 'status' => 'COMPLETED', 'score' => 72, 'completedAt' => '2026-10-08T11:30:00+02:00'],
                                ['assignmentId' => (string) $arrhythmia->id, 'status' => 'NOT_STARTED', 'score' => null, 'completedAt' => null],
                            ],
                        ],
                        [
                            'learner' => ['id' => (string) $otherSara->id, 'name' => 'Sara Lindqvist'],
                            'behind' => true,
                            'reasons' => ['Overdue: ECG Basics, due 2026-10-02, 7 days overdue'],
                            'averageScore' => null,
                            'counts' => ['notStarted' => 1, 'inProgress' => 0, 'completed' => 0, 'overdue' => 1],
                            'statuses' => [
                                ['assignmentId' => (string) $ecg->id, 'status' => 'OVERDUE', 'score' => null, 'completedAt' => null],
                                ['assignmentId' => (string) $arrhythmia->id, 'status' => 'NOT_STARTED', 'score' => null, 'completedAt' => null],
                            ],
                        ],
                        [
                            'learner' => ['id' => (string) $tom->id, 'name' => 'Tom Becker'],
                            'behind' => true,
                            'reasons' => ['Overdue: ECG Basics, due 2026-10-02, 7 days overdue'],
                            'averageScore' => null,
                            'counts' => ['notStarted' => 1, 'inProgress' => 1, 'completed' => 0, 'overdue' => 1],
                            'statuses' => [
                                ['assignmentId' => (string) $ecg->id, 'status' => 'OVERDUE', 'score' => null, 'completedAt' => null],
                                ['assignmentId' => (string) $heartFailure->id, 'status' => 'NOT_STARTED', 'score' => null, 'completedAt' => null],
                                ['assignmentId' => (string) $arrhythmia->id, 'status' => 'IN_PROGRESS', 'score' => null, 'completedAt' => null],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
});

test('the answer equals GroupProgress::for field for field', function () use ($askFor) {
    seed([ContentSeeder::class, DemoSeeder::class]);
    $educator = User::query()->where('email', 'anna.keller@example.edu')->sole();
    $group = Group::query()->where('name', 'Cardiology Group A')->sole();
    actingAsEducator($educator);

    $answer = $askFor($group->id)->assertOk()->json('data.groupProgress');
    $result = GroupProgress::for($educator, $group->id);

    expect($answer)->toBe(json_decode((string) json_encode([
        'group' => ['id' => (string) $result->groupId, 'name' => $result->groupName, 'institution' => $result->institutionName],
        'generatedAt' => $result->generatedAt->toIso8601String(),
        'assignments' => array_map(fn (AssignmentStats $assignment) => [
            'id' => (string) $assignment->id,
            'title' => $assignment->title,
            'topic' => $assignment->topic,
            'type' => strtoupper($assignment->type->value),
            'dueAt' => $assignment->dueAt->toIso8601String(),
            'scope' => strtoupper($assignment->scope->value),
            'targetedCount' => $assignment->targetedCount,
            'completedCount' => $assignment->completedCount,
            'completionRate' => $assignment->completionRate,
            'averageScore' => $assignment->averageScore,
        ], $result->assignments),
        'learners' => array_map(fn (LearnerProgress $learner) => [
            'learner' => ['id' => (string) $learner->learnerId, 'name' => $learner->learnerName],
            'behind' => $learner->behind,
            'reasons' => array_map(fn (Reason $reason) => $reason->text(), $learner->reasons),
            'averageScore' => $learner->averageScore,
            'counts' => [
                'notStarted' => $learner->counts->notStarted,
                'inProgress' => $learner->counts->inProgress,
                'completed' => $learner->counts->completed,
                'overdue' => $learner->counts->overdue,
            ],
            'statuses' => array_map(fn (AssignmentStatus $status) => [
                'assignmentId' => (string) $status->assignmentId,
                'status' => strtoupper($status->status->value),
                'score' => $status->score,
                'completedAt' => $status->completedAt?->toIso8601String(),
            ], $learner->statuses),
        ], $result->learners),
    ]), true))
        ->and($result->behindCount)->toBeGreaterThan(0)
        ->and($result->behindCount)->toBeLessThan($result->learnerCount);
});

test('another educator\'s group, a missing id and a malformed id answer the same not found error', function () use ($askFor) {
    $group = Group::factory()->create();
    educatorOf($group);
    learnerIn($group);
    Assignment::factory()->for($group)->create();
    $ownGroup = Group::factory()->create(['institution_id' => $group->institution_id]);
    actingAsEducator(educatorOf($ownGroup));
    $notFound = [
        'data' => ['groupProgress' => null],
        'errors' => [
            ['message' => 'Group not found.', 'path' => ['groupProgress'], 'extensions' => ['code' => 'not_found']],
        ],
    ];

    $foreign = $askFor($group->id)->assertOk()->assertExactJson($notFound);
    $missing = $askFor($group->id + 1000)->assertOk()->assertExactJson($notFound);
    $malformed = $askFor("{$ownGroup->id}abc")->assertOk()->assertExactJson($notFound);

    expect($foreign->getContent())->toBe($missing->getContent())
        ->and($malformed->getContent())->toBe($missing->getContent());
});

test('no token answers 401 in the REST error shape', function () use ($askFor) {
    $group = Group::factory()->create();

    $askFor($group->id)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']]);
});

test('a learner\'s token answers 403 forbidden in the REST error shape', function () use ($askFor) {
    $group = Group::factory()->create();
    actingAsLearner(learnerIn($group));

    $askFor($group->id)
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);
});

test('GET /graphql answers 405 and runs no query', function () use ($query) {
    $group = Group::factory()->create();
    actingAsEducator(educatorOf($group));

    getJson('/graphql?'.http_build_query(['query' => $query, 'variables' => json_encode(['groupId' => (string) $group->id])]))
        ->assertMethodNotAllowed()
        ->assertHeader('Allow', 'POST')
        ->assertExactJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed.']]);
});

test('the request runs exactly two queries whatever the group size', function (int $learnerCount, int $assignmentCount) use ($askFor) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    seedLargeGroup($group, $educator, $learnerCount, $assignmentCount);
    actingAsEducator($educator);

    DB::enableQueryLog();
    $response = $askFor($group->id);
    DB::disableQueryLog();

    $response->assertOk()
        ->assertJsonCount($learnerCount, 'data.groupProgress.learners')
        ->assertJsonCount($assignmentCount + 1, 'data.groupProgress.assignments');
    expect(DB::getQueryLog())->toHaveCount(2);
})->with([
    '60 learners and 100 assignments' => [60, 100],
    '120 learners and 200 assignments' => [120, 200],
]);

test('an unknown field answers a GraphQL validation error with HTTP 200 and no data', function () {
    $group = Group::factory()->create();
    actingAsEducator(educatorOf($group));

    postJson('/graphql', ['query' => "{ groupProgress(groupId: \"{$group->id}\") { group { id } secret } }"])
        ->assertOk()
        ->assertJsonMissingPath('data')
        ->assertJsonPath('errors.0.message', 'Cannot query field "secret" on type "GroupProgress".');
});

test('an unexpected failure answers a generic error without its message when debug is off', function () use ($askFor) {
    config(['app.debug' => false]);
    Exceptions::fake();
    $institution = Institution::factory()->create(['timezone' => 'Mars/Olympus_Mons']);
    $group = Group::factory()->for($institution)->create();
    actingAsEducator(educatorOf($group));

    $response = $askFor($group->id)
        ->assertOk()
        ->assertJsonPath('data.groupProgress', null)
        ->assertJsonPath('errors.0.message', 'Internal server error')
        ->assertJsonMissingPath('errors.0.extensions');

    expect($response->getContent())->not->toContain('Mars');
    Exceptions::assertReported(DateInvalidTimeZoneException::class);
});
