<?php

use App\Enums\ContentType;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Progress;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('lists every active assignment that targets the caller across their groups, with the derived status', function () {
    [$cardiology, $educator, $questionSet, $priya, $sara, $tom] = cardiologyGroup();
    $pulmonology = Group::factory()->for($cardiology->institution)->create(['name' => 'Pulmonology Group B']);
    $pulmonology->learners()->attach($sara);
    $article = ContentItem::factory()->create(['title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => ContentType::Article, 'estimated_minutes' => 20]);
    $asthma = ContentItem::factory()->create(['title' => 'Asthma Question Set', 'topic' => 'Pulmonology', 'type' => ContentType::QuestionSet, 'estimated_minutes' => 25]);

    $notStarted = Assignment::factory()->for($cardiology)->for($questionSet, 'contentItem')->create(['due_at' => '2026-10-16 21:59:59']);
    $inProgress = Assignment::factory()->for($cardiology)->for($article, 'contentItem')->create(['due_at' => '2026-10-16 21:59:59']);
    $overdue = Assignment::factory()->for($pulmonology)->create(['due_at' => '2026-10-05 21:59:59']);
    $completed = Assignment::factory()->for($pulmonology)->for($asthma, 'contentItem')->forLearners([$sara])->create(['due_at' => '2026-10-02 21:59:59']);
    Assignment::factory()->for($cardiology)->forLearners([$tom])->create();
    Assignment::factory()->for($cardiology)->removed()->create();
    Assignment::factory()->create();

    Progress::factory()->for($inProgress)->for($sara, 'user')->create(['started_at' => '2026-10-08 06:12:00']);
    Progress::factory()->for($completed)->for($sara, 'user')->completed(72)
        ->create(['started_at' => '2026-10-01 07:00:00', 'completed_at' => '2026-10-01 08:30:00']);
    Progress::factory()->for($notStarted)->for($priya, 'user')->create();

    actingAsLearner($sara);

    $response = getJson('/api/v1/me/assignments')->assertOk();

    expect($response->json('data.*.assignment_id'))->toBe([$completed->id, $overdue->id, $notStarted->id, $inProgress->id])
        ->and($response->json('data.*.status'))->toBe(['completed', 'overdue', 'not_started', 'in_progress'])
        ->and($response->json('meta'))->toBe(['next_cursor' => null])
        ->and($response->json('data.0'))->toBe([
            'assignment_id' => $completed->id,
            'group' => ['id' => $pulmonology->id, 'name' => 'Pulmonology Group B'],
            'content' => ['id' => $asthma->id, 'title' => 'Asthma Question Set', 'topic' => 'Pulmonology', 'type' => 'question_set', 'estimated_minutes' => 25],
            'due_at' => '2026-10-02T23:59:59+02:00',
            'status' => 'completed',
            'score' => 72,
            'started_at' => '2026-10-01T09:00:00+02:00',
            'completed_at' => '2026-10-01T10:30:00+02:00',
        ])
        ->and($response->json('data.2'))->toBe([
            'assignment_id' => $notStarted->id,
            'group' => ['id' => $cardiology->id, 'name' => 'Cardiology Group A'],
            'content' => ['id' => $questionSet->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'estimated_minutes' => 30],
            'due_at' => '2026-10-16T23:59:59+02:00',
            'status' => 'not_started',
            'score' => null,
            'started_at' => null,
            'completed_at' => null,
        ])
        ->and($response->json('data.3'))->toBe([
            'assignment_id' => $inProgress->id,
            'group' => ['id' => $cardiology->id, 'name' => 'Cardiology Group A'],
            'content' => ['id' => $article->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'estimated_minutes' => 20],
            'due_at' => '2026-10-16T23:59:59+02:00',
            'status' => 'in_progress',
            'score' => null,
            'started_at' => '2026-10-08T08:12:00+02:00',
            'completed_at' => null,
        ]);
});

test('an assignment moves to overdue once its due date passes', function () {
    [$group, , , $priya] = cardiologyGroup();
    Assignment::factory()->for($group)->create(['due_at' => '2026-10-09 12:00:00']);
    $inProgress = Assignment::factory()->for($group)->create(['due_at' => '2026-10-09 12:00:00']);
    Progress::factory()->for($inProgress)->for($priya, 'user')->create();

    actingAsLearner($priya);

    expect(getJson('/api/v1/me/assignments')->json('data.*.status'))->toBe(['not_started', 'in_progress']);

    CarbonImmutable::setTestNow('2026-10-09 12:00:01');

    expect(getJson('/api/v1/me/assignments')->json('data.*.status'))->toBe(['overdue', 'overdue']);
});

test('a learner with no group or no assignment gets an empty page', function () {
    actingAsLearner();

    getJson('/api/v1/me/assignments')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);
});

test('pages with limit and cursor in due date then id order', function () {
    [$group, , , $priya] = cardiologyGroup();
    $other = Group::factory()->for($group->institution)->create();
    $other->learners()->attach($priya);
    $assignments = collect([
        [$group, '2026-10-20 10:00:00'],
        [$other, '2026-10-12 10:00:00'],
        [$group, '2026-10-12 10:00:00'],
        [$other, '2026-10-01 10:00:00'],
    ])->map(fn (array $pair) => Assignment::factory()->for($pair[0])->create(['due_at' => $pair[1]]));
    $expected = [$assignments[3]->id, $assignments[1]->id, $assignments[2]->id, $assignments[0]->id];

    actingAsLearner($priya);

    $ids = [];
    $cursor = null;

    do {
        $page = getJson('/api/v1/me/assignments?'.http_build_query(['limit' => 1, 'cursor' => $cursor]))->assertOk()->assertJsonCount(1, 'data');
        $ids = [...$ids, ...$page->json('data.*.assignment_id')];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($ids)->toBe($expected);

    getJson('/api/v1/me/assignments?limit=201')->assertUnprocessable();
    getJson('/api/v1/me/assignments?cursor=garbage')
        ->assertUnprocessable()
        ->assertJsonPath('error.details.cursor', ['The cursor is invalid.']);
});

test('the list runs one query for 5 and for 100 assignments', function () {
    withoutMiddleware(ThrottleRequests::class);
    [$group, , , $priya, $sara] = cardiologyGroup();
    $other = Group::factory()->for($group->institution)->create();
    $other->learners()->attach($priya);

    $queryCount = function (int $assignments) use ($group, $other, $priya, $sara): int {
        Assignment::query()->get()->each(fn (Assignment $assignment) => $assignment->update([
            'removed_at' => now(),
            'removed_key' => $assignment->id,
        ]));
        foreach (range(1, $assignments) as $index) {
            $factory = Assignment::factory()->for($index % 3 === 0 ? $other : $group);
            $assignment = ($index % 2 === 0 ? $factory->forLearners([$priya, $sara]) : $factory)->create();

            if ($index % 4 === 0) {
                Progress::factory()->for($assignment)->for($priya, 'user')->create();
            }
        }

        actingAsLearner($priya);
        DB::enableQueryLog();
        DB::flushQueryLog();

        getJson('/api/v1/me/assignments?limit=200')->assertOk()->assertJsonCount($assignments, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queryCount(5))->toBe(1)
        ->and($queryCount(100))->toBe(1);
});

test('an educator answers 403', function () {
    [, $educator] = cardiologyGroup();

    actingAsEducator($educator);

    getJson('/api/v1/me/assignments')
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);
});
