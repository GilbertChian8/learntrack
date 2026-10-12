<?php

use App\Models\Assignment;
use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('lists the active assignments ordered by due_at then id, each with its targeted learners', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    $ecg = ContentItem::factory()->create(['title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'estimated_minutes' => 20]);
    $lipids = ContentItem::factory()->create();
    $subset = Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$tom, $sara])
        ->create(['due_at' => '2026-10-16 21:59:59', 'created_by' => $educator->id, 'created_at' => '2026-10-01 07:00:00']);
    $whole = Assignment::factory()->for($group)->for($ecg, 'contentItem')
        ->create(['due_at' => '2026-10-16 21:59:59', 'created_by' => $educator->id, 'created_at' => '2026-10-02 07:00:00']);
    $earliest = Assignment::factory()->for($group)->create(['due_at' => '2026-10-02 21:59:59']);
    Assignment::factory()->for($group)->for($lipids, 'contentItem')->removed()->create();
    Assignment::factory()->create();

    actingAsEducator($educator);

    $response = getJson("/api/v1/groups/{$group->id}/assignments")->assertOk();

    expect($response->json('data.*.id'))->toBe([$earliest->id, $subset->id, $whole->id])
        ->and($response->json('meta'))->toBe(['next_cursor' => null])
        ->and($response->json('data.1'))->toBe([
            'id' => $subset->id,
            'group_id' => $group->id,
            'content' => ['id' => $content->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'estimated_minutes' => 30],
            'due_at' => '2026-10-16T23:59:59+02:00',
            'scope' => 'learners',
            'targeted_count' => 2,
            'learners' => [
                ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
                ['id' => $tom->id, 'name' => 'Tom Becker'],
            ],
            'created_at' => '2026-10-01T09:00:00+02:00',
        ])
        ->and($response->json('data.2'))->toBe([
            'id' => $whole->id,
            'group_id' => $group->id,
            'content' => ['id' => $ecg->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'estimated_minutes' => 20],
            'due_at' => '2026-10-16T23:59:59+02:00',
            'scope' => 'group',
            'targeted_count' => 3,
            'learners' => null,
            'created_at' => '2026-10-02T09:00:00+02:00',
        ]);
});

test('learners and targeted_count drop by one when a targeted learner leaves the group', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    $subset = Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$sara, $tom])->create();
    $whole = Assignment::factory()->for($group)->create();

    actingAsEducator($educator);

    $before = getJson("/api/v1/groups/{$group->id}/assignments")->assertOk();
    deleteJson("/api/v1/groups/{$group->id}/learners/{$tom->id}")->assertNoContent();
    $after = getJson("/api/v1/groups/{$group->id}/assignments")->assertOk();

    $byId = fn (TestResponse $response) => $response->collect('data')->keyBy('id');

    expect($byId($before)[$subset->id]['targeted_count'])->toBe(2)
        ->and($byId($after)[$subset->id]['targeted_count'])->toBe(1)
        ->and($byId($after)[$subset->id]['learners'])->toBe([['id' => $sara->id, 'name' => 'Sara Lindqvist']])
        ->and($byId($before)[$whole->id]['targeted_count'])->toBe(3)
        ->and($byId($after)[$whole->id]['targeted_count'])->toBe(2)
        ->and(DB::table('assignment_learners')->where('assignment_id', $subset->id)->count())->toBe(2);
});

test('an assignment whose learners all left the group targets nobody', function () {
    [$group, $educator, $content, $priya] = cardiologyGroup();
    Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$priya])->create();
    $group->learners()->detach($priya);

    actingAsEducator($educator);

    getJson("/api/v1/groups/{$group->id}/assignments")
        ->assertOk()
        ->assertJsonPath('data.0.targeted_count', 0)
        ->assertJsonPath('data.0.learners', []);
});

test('pages with limit and cursor in due date order', function () {
    [$group, $educator] = cardiologyGroup();
    $assignments = collect(['2026-10-20 10:00:00', '2026-10-12 10:00:00', '2026-10-12 10:00:00'])
        ->map(fn (string $dueAt) => Assignment::factory()->for($group)->create(['due_at' => $dueAt]));
    $expected = [$assignments[1]->id, $assignments[2]->id, $assignments[0]->id];

    actingAsEducator($educator);

    $ids = [];
    $cursor = null;

    do {
        $page = getJson("/api/v1/groups/{$group->id}/assignments?".http_build_query(['limit' => 1, 'cursor' => $cursor]))->assertOk();
        $ids = [...$ids, ...$page->json('data.*.id')];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($ids)->toBe($expected);

    getJson("/api/v1/groups/{$group->id}/assignments?limit=201")->assertUnprocessable();
    getJson("/api/v1/groups/{$group->id}/assignments?cursor=garbage")
        ->assertUnprocessable()
        ->assertJsonPath('error.details.cursor', ['The cursor is invalid.']);
});

test('the list runs the same number of queries for 5 and for 100 assignments', function () {
    withoutMiddleware(ThrottleRequests::class);
    [$group, $educator] = cardiologyGroup();
    $learners = $group->learners()->get()->all();

    $queryCount = function (int $assignments) use ($group, $educator, $learners): int {
        Assignment::query()->where('group_id', $group->id)->get()->each(fn (Assignment $assignment) => $assignment->update([
            'removed_at' => now(),
            'removed_key' => $assignment->id,
        ]));
        foreach (range(1, $assignments) as $index) {
            $factory = Assignment::factory()->for($group);
            ($index % 2 === 0 ? $factory->forLearners(array_slice($learners, 0, 2)) : $factory)->create(['created_by' => $educator->id]);
        }

        actingAsEducator($educator);
        DB::enableQueryLog();
        DB::flushQueryLog();

        getJson("/api/v1/groups/{$group->id}/assignments?limit=200")->assertOk()->assertJsonCount($assignments, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queryCount(5))->toBe(5)
        ->and($queryCount(100))->toBe(5);
});
