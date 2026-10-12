<?php

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\seed;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

/**
 * The read tool sections of mcp-tools.md section 3, keyed by tool name.
 *
 * @return array<string, string>
 */
$readToolSections = function (): array {
    $section = Str::between((string) file_get_contents(base_path('docs/mcp-tools.md')), "\n## 3. Read tools\n", "\n## 4. ");
    preg_match_all('/^### (\w+)\n(.*?)(?=^### |\z)/ms', $section, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $match) => [$match[1] => $match[2]])->all();
};

test('tools/list publishes the five read tools with the contract title, description, input schema and annotations', function () use ($readToolSections) {
    $sections = $readToolSections();
    actingAsEducator();

    expect(array_keys($sections))->toBe(['list_my_groups', 'get_group_progress', 'find_learners_behind', 'get_learner_summary', 'find_hardest_assignments']);

    foreach ($sections as $name => $section) {
        preg_match('/^\| Title \| (.+) \|$/m', $section, $title);
        preg_match('/^Description:\n\n> (.+)$/m', $section, $description);
        preg_match('/^Input schema:\n\n```json\n(.*?)\n```/ms', $sections[$name === 'find_learners_behind' ? 'get_group_progress' : $name], $inputSchema);
        $tool = listedTool($name);

        expect($tool['title'])->toBe($title[1])
            ->and($tool['description'])->toBe($description[1])
            ->and($tool['inputSchema'])->toEqual(json_decode($inputSchema[1], true))
            ->and($tool['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true])
            ->and($tool['outputSchema']['type'])->toBe('object');
    }
});

test('a numeric string for an integer argument answers a tool error and reads nothing', function (string $tool, array $arguments, string $message) {
    actingAsEducator();

    DB::enableQueryLog();
    $result = callTool($tool, $arguments)->json('result');
    DB::disableQueryLog();

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => $message]])
        ->and(DB::getQueryLog())->toBe([]);
})->with([
    'get_group_progress' => ['get_group_progress', ['group_id' => '12'], 'The group id field must be an integer.'],
    'find_learners_behind' => ['find_learners_behind', ['group_id' => '12'], 'The group id field must be an integer.'],
    'find_hardest_assignments' => ['find_hardest_assignments', ['group_id' => '12'], 'The group id field must be an integer.'],
    'find_hardest_assignments limit' => ['find_hardest_assignments', ['group_id' => 12, 'limit' => '5'], 'The limit field must be an integer.'],
    'get_learner_summary' => ['get_learner_summary', ['learner_id' => '101'], 'The learner id field must be an integer.'],
]);

test('every example in mcp-tools.md section 3 matches its tool\'s published output schema', function () use ($readToolSections) {
    actingAsEducator();
    $exampleCount = 0;

    foreach ($readToolSections() as $name => $section) {
        preg_match_all('/^Example[^\n]*:\n\n```json\n(.*?)\n```/ms', $section, $examples);

        foreach ($examples[1] as $example) {
            $exampleCount++;

            expect(schemaErrors(listedTool($name)['outputSchema'], json_decode($example, true, flags: JSON_THROW_ON_ERROR)))->toBe([], "{$name} example");
        }
    }

    expect($exampleCount)->toBe(6);
});

test('every read tool\'s answer on the seeded data matches its published output schema', function () {
    seed([ContentSeeder::class, DemoSeeder::class]);
    $educator = User::query()->where('email', 'anna.keller@example.edu')->sole();
    $group = Group::query()->where('name', 'Cardiology Group A')->sole();
    $sara = User::query()->where('email', 'sara.lindqvist@example.edu')->sole();
    actingAsEducator($educator);

    $calls = [
        ['list_my_groups', []],
        ['get_group_progress', ['group_id' => $group->id]],
        ['find_learners_behind', ['group_id' => $group->id]],
        ['get_learner_summary', ['learner_id' => $sara->id]],
        ['get_learner_summary', ['name' => 'an']],
        ['find_hardest_assignments', ['group_id' => $group->id, 'limit' => 20]],
    ];

    foreach ($calls as [$name, $arguments]) {
        expect(schemaErrors(listedTool($name)['outputSchema'], callTool($name, $arguments)->json('result')['structuredContent']))->toBe([], $name);
    }
});

test('a schema error is found: a learner or group with an email does not match', function () {
    actingAsEducator();
    $group = ['id' => 12, 'name' => 'Cardiology Group A', 'institution' => 'Northside Medical School', 'learner_count' => 42, 'assignment_count' => 9];

    expect(schemaErrors(listedTool('list_my_groups')['outputSchema'], ['groups' => [[...$group, 'email' => 'anna@example.edu']]]))
        ->toBe(['$.groups[0].email is not allowed']);
});
