<?php

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Arr;

use function Pest\Laravel\seed;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

test('no tool answer on the seeded data has an email key or an @ in its text', function () {
    seed([ContentSeeder::class, DemoSeeder::class]);
    $educator = User::query()->where('email', 'anna.keller@example.edu')->sole();
    $group = Group::query()->where('name', 'Cardiology Group A')->sole();
    $sara = User::query()->where('email', 'sara.lindqvist@example.edu')->sole();

    $results = [
        callTool($educator, 'list_my_groups'),
        callTool($educator, 'get_group_progress', ['group_id' => $group->id]),
        callTool($educator, 'find_learners_behind', ['group_id' => $group->id]),
        callTool($educator, 'get_learner_summary', ['learner_id' => $sara->id]),
        callTool($educator, 'get_learner_summary', ['name' => 'an']),
        callTool($educator, 'find_hardest_assignments', ['group_id' => $group->id, 'limit' => 20]),
    ];

    expect($results[4]['structuredContent']['candidates'])->not->toBe([]);

    foreach ($results as $result) {
        expect($result['isError'])->toBeFalse()
            ->and(collect(array_keys(Arr::dot($result['structuredContent'])))->flatMap(fn (string $key) => explode('.', $key))->all())->not->toContain('email')
            ->and($result['content'])->toHaveCount(1)
            ->and($result['content'][0]['text'])->not->toContain('@');
    }
});
