<?php

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoSeeder;
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

test('tools/list publishes the five read tools with the contract name, title, description and input schema', function () use ($readToolSections) {
    $sections = $readToolSections();
    $tools = publishedTools(User::factory()->educator()->create());

    expect(array_keys($sections))->toBe(['list_my_groups', 'get_group_progress', 'find_learners_behind', 'get_learner_summary', 'find_hardest_assignments'])
        ->and(array_keys($tools))->toBe(array_keys($sections));

    foreach ($sections as $name => $section) {
        preg_match('/^\| Title \| (.+) \|$/m', $section, $title);
        preg_match('/^Description:\n\n> (.+)$/m', $section, $description);
        preg_match('/^Input schema:\n\n```json\n(.*?)\n```/ms', $sections[$name === 'find_learners_behind' ? 'get_group_progress' : $name], $inputSchema);

        expect($tools[$name]['title'])->toBe($title[1])
            ->and($tools[$name]['description'])->toBe($description[1])
            ->and($tools[$name]['inputSchema'])->toEqual(json_decode($inputSchema[1], true))
            ->and($tools[$name]['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true])
            ->and($tools[$name]['outputSchema']['type'])->toBe('object')
            ->and($tools[$name]['outputSchema']['additionalProperties'])->toBeFalse();
    }
});

test('every example in mcp-tools.md section 3 matches its tool\'s published output schema', function () use ($readToolSections) {
    $tools = publishedTools(User::factory()->educator()->create());
    $exampleCount = 0;

    foreach ($readToolSections() as $name => $section) {
        preg_match_all('/^Example[^\n]*:\n\n```json\n(.*?)\n```/ms', $section, $examples);

        foreach ($examples[1] as $example) {
            $exampleCount++;

            expect(schemaErrors($tools[$name]['outputSchema'], json_decode($example, true, flags: JSON_THROW_ON_ERROR)))->toBe([], "{$name} example");
        }
    }

    expect($exampleCount)->toBe(6);
});

test('every read tool\'s answer on the seeded data matches its published output schema', function () {
    seed([ContentSeeder::class, DemoSeeder::class]);
    $educator = User::query()->where('email', 'anna.keller@example.edu')->sole();
    $group = Group::query()->where('name', 'Cardiology Group A')->sole();
    $sara = User::query()->where('email', 'sara.lindqvist@example.edu')->sole();
    $tools = publishedTools($educator);

    $calls = [
        ['list_my_groups', []],
        ['get_group_progress', ['group_id' => $group->id]],
        ['find_learners_behind', ['group_id' => $group->id]],
        ['get_learner_summary', ['learner_id' => $sara->id]],
        ['get_learner_summary', ['name' => 'an']],
        ['find_hardest_assignments', ['group_id' => $group->id, 'limit' => 20]],
    ];

    foreach ($calls as [$name, $arguments]) {
        expect(schemaErrors($tools[$name]['outputSchema'], callTool($educator, $name, $arguments)['structuredContent']))->toBe([], $name);
    }
});

test('a schema error is found: an answer with an extra field does not match', function () {
    $tools = publishedTools(User::factory()->educator()->create());

    expect(schemaErrors($tools['list_my_groups']['outputSchema'], ['groups' => [], 'email' => 'anna@example.edu']))
        ->toBe(['$.email is not allowed']);
});
