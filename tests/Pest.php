<?php

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Progress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit/Actions', 'Unit/Policies', 'Unit/Queries');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Helpers shared by the test files. They live here, not in a test file, so
| no two files can declare the same global function.
|
*/

/**
 * Tests authenticate with Passport::actingAs, as these two helpers do.
 */
function actingAsEducator(?User $educator = null): User
{
    $educator ??= User::factory()->educator()->create();

    Passport::actingAs($educator);

    return $educator;
}

function actingAsLearner(?User $learner = null): User
{
    $learner ??= User::factory()->learner()->create();

    Passport::actingAs($learner);

    return $learner;
}

function educatorOf(Group $group): User
{
    $educator = User::factory()->educator()->create(['institution_id' => $group->institution_id]);
    $group->educators()->attach($educator);

    return $educator;
}

function learnerIn(Group $group): User
{
    $learner = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    $group->learners()->attach($learner);

    return $learner;
}

/**
 * Cardiology Group A (Europe/Berlin), taught by Anna Keller, with Priya Nair,
 * Sara Lindqvist and Tom Becker, and the Heart Failure Question Set.
 *
 * @return array{Group, User, ContentItem, User, User, User} group, educator, content, Priya, Sara, Tom
 */
function cardiologyGroup(): array
{
    $institution = Institution::factory()->create(['name' => 'Northside Medical School', 'timezone' => 'Europe/Berlin']);
    $group = Group::factory()->for($institution)->create(['name' => 'Cardiology Group A']);
    $educator = User::factory()->educator()->for($institution)->create(['name' => 'Anna Keller']);
    $group->educators()->attach($educator);
    $content = ContentItem::factory()->create([
        'title' => 'Heart Failure Question Set',
        'topic' => 'Cardiology',
        'type' => ContentType::QuestionSet,
        'estimated_minutes' => 30,
    ]);

    $learners = collect(['Priya Nair', 'Sara Lindqvist', 'Tom Becker'])->map(function (string $name) use ($institution, $group): User {
        $learner = User::factory()->learner()->for($institution)->create(['name' => $name]);
        $group->learners()->attach($learner);

        return $learner;
    });

    return [$group, $educator, $content, ...$learners->all()];
}

/**
 * cardiologyGroup() with five assignments and progress, read at 2026-10-09 12:20 UTC:
 * Priya is on track, Sara is behind by a low average, Tom by two overdue assignments.
 * Lipid Disorders targets nobody.
 *
 * @return array{group: Group, educator: User, priya: User, sara: User, tom: User, assignments: array{ecg: Assignment, arrhythmia: Assignment, heartFailure: Assignment, lipids: Assignment, cardiomyopathy: Assignment}}
 */
function cardiologyProgress(): array
{
    [$group, $educator, $heartFailure, $priya, $sara, $tom] = cardiologyGroup();

    $content = fn (string $title, ContentType $type) => ContentItem::factory()->create(['title' => $title, 'topic' => 'Cardiology', 'type' => $type]);
    $assign = fn (ContentItem $content, string $dueAt, AssignmentScope $scope = AssignmentScope::Group) => Assignment::factory()
        ->for($group)->for($content, 'contentItem')
        ->create(['due_at' => $dueAt, 'scope' => $scope, 'created_by' => $educator->id]);
    $complete = fn (Assignment $assignment, User $learner, ?int $score, string $at) => Progress::factory()
        ->for($assignment)->for($learner, 'user')->completed($score)
        ->create(['started_at' => $at, 'completed_at' => $at]);

    $assignments = [
        'ecg' => $assign($content('ECG Basics', ContentType::Article), '2026-10-02 21:59:59'),
        'arrhythmia' => $assign($content('Arrhythmia Question Set', ContentType::QuestionSet), '2026-10-05 21:59:59'),
        'heartFailure' => $assign($heartFailure, '2026-10-16 21:59:59', AssignmentScope::Learners),
        'lipids' => $assign($content('Lipid Disorders Question Set', ContentType::QuestionSet), '2026-10-20 21:59:59', AssignmentScope::Learners),
        'cardiomyopathy' => $assign($content('Cardiomyopathy Question Set', ContentType::QuestionSet), '2026-10-23 21:59:59'),
    ];
    $assignments['heartFailure']->learners()->attach([$sara->id, $tom->id]);

    $complete($assignments['ecg'], $priya, null, '2026-10-01 08:00:00');
    $complete($assignments['ecg'], $sara, null, '2026-10-01 16:02:00');
    $complete($assignments['arrhythmia'], $priya, 90, '2026-10-04 10:00:00');
    $complete($assignments['arrhythmia'], $sara, 45, '2026-10-05 09:00:00');
    $complete($assignments['heartFailure'], $sara, 40, '2026-10-08 18:15:00');
    Progress::factory()->for($assignments['heartFailure'])->for($tom, 'user')->create(['started_at' => '2026-10-08 09:00:00']);

    return compact('group', 'educator', 'priya', 'sara', 'tom', 'assignments');
}

/**
 * Calls a tool on POST /mcp as the educator and returns the JSON-RPC result.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callTool(User $educator, string $name, array $arguments = []): array
{
    Passport::actingAs($educator);

    return postJson('/mcp', mcpRequest('tools/call', ['name' => $name, 'arguments' => (object) $arguments]))
        ->assertOk()
        ->assertJsonMissingPath('error')
        ->json('result');
}

/**
 * The tools as tools/list publishes them, keyed by name.
 *
 * @return array<string, array<string, mixed>>
 */
function publishedTools(User $educator): array
{
    Passport::actingAs($educator);

    return array_column(postJson('/mcp', mcpRequest('tools/list'))->assertOk()->json('result.tools'), null, 'name');
}

/**
 * One row as PairRows returns it.
 *
 * @param  array<string, mixed>  $columns
 */
function pairRow(array $columns = []): stdClass
{
    return (object) [
        'group_id' => 12,
        'group_name' => 'Cardiology Group A',
        'user_id' => 101,
        'learner_name' => 'Sara Lindqvist',
        'assignment_id' => 41,
        'scope' => 'group',
        'due_at' => '2026-10-16 21:59:59',
        'content_item_id' => 8,
        'title' => 'Heart Failure Question Set',
        'topic' => 'Cardiology',
        'type' => 'question_set',
        'estimated_minutes' => 30,
        'progress_status' => null,
        'score' => null,
        'started_at' => null,
        'completed_at' => null,
        'status' => 'not_started',
        ...$columns,
    ];
}

/**
 * $learnerCount learners and $assignmentCount + 1 assignments in the group, with bulk inserts.
 * The last assignment targets five learners; about half of the pairs have progress.
 */
function seedLargeGroup(Group $group, User $educator, int $learnerCount, int $assignmentCount): void
{
    $stamp = now()->toDateTimeString();

    DB::table('users')->insert(array_map(fn (int $i) => [
        'institution_id' => $group->institution_id,
        'name' => "Learner {$i}",
        'email' => "learner{$i}@example.edu",
        'password' => 'unused',
        'role' => 'learner',
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], range(1, $learnerCount)));
    $learnerIds = DB::table('users')->where('role', 'learner')->orderBy('id')->pluck('id')->all();
    DB::table('group_learners')->insert(array_map(fn (int $id) => ['group_id' => $group->id, 'user_id' => $id, 'created_at' => $stamp], $learnerIds));

    DB::table('content_items')->insert(array_map(fn (int $i) => [
        'title' => "Content {$i}",
        'topic' => 'Cardiology',
        'type' => $i % 2 === 0 ? 'question_set' : 'article',
        'estimated_minutes' => 20,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], range(0, $assignmentCount)));
    $contentIds = DB::table('content_items')->orderBy('id')->pluck('id')->all();

    DB::table('assignments')->insert(array_map(fn (int $i) => [
        'group_id' => $group->id,
        'content_item_id' => $contentIds[$i],
        'due_at' => now()->addDays($i % 30 - 15)->toDateTimeString(),
        'scope' => $i === $assignmentCount ? 'learners' : 'group',
        'created_by' => $educator->id,
        'removed_key' => 0,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], array_keys($contentIds)));
    $assignmentIds = DB::table('assignments')->orderBy('id')->pluck('id')->all();
    DB::table('assignment_learners')->insert(array_map(
        fn (int $id) => ['assignment_id' => $assignmentIds[$assignmentCount], 'user_id' => $id, 'created_at' => $stamp],
        array_slice($learnerIds, 0, 5),
    ));

    $progress = [];
    foreach (array_slice($assignmentIds, 0, $assignmentCount) as $a => $assignmentId) {
        foreach ($learnerIds as $l => $learnerId) {
            if (($a + $l) % 2 === 1) {
                continue;
            }

            $completed = $l % 4 !== 0;
            $progress[] = [
                'assignment_id' => $assignmentId,
                'user_id' => $learnerId,
                'status' => $completed ? 'completed' : 'in_progress',
                'score' => $completed && $a % 2 === 0 ? ($a * 7 + $l * 3) % 101 : null,
                'started_at' => $stamp,
                'completed_at' => $completed ? now()->subMinutes($a * $learnerCount + $l)->toDateTimeString() : null,
            ];

            if (count($progress) === 1000) {
                DB::table('progress')->insert($progress);
                $progress = [];
            }
        }
    }
    DB::table('progress')->insert($progress);
}

/**
 * @return array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}|null
 */
function findIndex(string $table, string $name): ?array
{
    return collect(Schema::getIndexes($table))->firstWhere('name', $name);
}

/**
 * Per group and learner: overdue, not completed pairs and the average score
 * of completed question sets, over the active assignments that target the
 * learner. Plain SQL, since BehindRule is ticket 09.
 *
 * @return list<object{group_id: int, user_id: int, overdue: int, average_score: string|null}>
 */
function learnerStanding(): array
{
    return DB::select(
        "select gl.group_id, gl.user_id,
                coalesce(sum(a.due_at < ? and (p.status is null or p.status <> 'completed')), 0) as overdue,
                avg(case when c.type = 'question_set' and p.status = 'completed' then p.score end) as average_score
           from group_learners gl
           left join assignments a on a.group_id = gl.group_id
                and a.removed_at is null
                and (a.scope = 'group' or exists (
                    select 1 from assignment_learners al where al.assignment_id = a.id and al.user_id = gl.user_id))
           left join content_items c on c.id = a.content_item_id
           left join progress p on p.assignment_id = a.id and p.user_id = gl.user_id
          group by gl.group_id, gl.user_id",
        [now()->toDateTimeString()],
    );
}

/**
 * A JSON-RPC request body for POST /mcp.
 *
 * @param  array<string, mixed>  $params
 * @return array{jsonrpc: string, id: int, method: string, params: array<string, mixed>}
 */
function mcpRequest(string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
}

/**
 * Registers a public client the way Claude does, and returns its id.
 */
function registerOAuthClient(string $redirectUri = 'http://localhost:9999/callback'): string
{
    $response = postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => [$redirectUri]])->assertCreated();

    return $response->json('client_id');
}

/**
 * An authorization request with an S256 challenge for the given verifier.
 *
 * @return array<string, string>
 */
function authorizationQuery(string $clientId, string $verifier, string $redirectUri = 'http://localhost:9999/callback'): array
{
    return [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'state-123',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ];
}

/**
 * The query string of a redirect back to the client.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, string>
 */
function callbackQuery(TestResponse $response, string $redirectUri): array
{
    $location = (string) $response->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith($redirectUri.'?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

function secondsFromNow(string $dateTime): int
{
    return (int) now()->diffInSeconds(CarbonImmutable::parse($dateTime), absolute: true);
}

/**
 * The errors of a value against the JSON Schema subset the MCP tools publish:
 * type, enum, properties, required, additionalProperties, items and anyOf.
 *
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function schemaErrors(array $schema, mixed $value, string $path = '$'): array
{
    if (isset($schema['anyOf'])) {
        foreach ($schema['anyOf'] as $option) {
            if (schemaErrors($option, $value, $path) === []) {
                return [];
            }
        }

        return ["{$path} matches no anyOf option"];
    }

    $types = (array) $schema['type'];
    $type = match (true) {
        is_null($value) => 'null',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_string($value) => 'string',
        is_array($value) && array_is_list($value) && $value !== [] => 'array',
        is_array($value) => in_array('object', $types, true) ? 'object' : 'array',
        default => get_debug_type($value),
    };

    if (! in_array($type, $types, true) && ! ($type === 'integer' && in_array('number', $types, true))) {
        return ["{$path} is {$type}, not ".implode('|', $types)];
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        return ["{$path} is not in the enum"];
    }

    $errors = [];

    if ($type === 'object') {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path}.{$key} is missing";
            }
        }

        foreach ($value as $key => $item) {
            if (isset($schema['properties'][$key])) {
                $errors = [...$errors, ...schemaErrors($schema['properties'][$key], $item, "{$path}.{$key}")];
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$path}.{$key} is not allowed";
            }
        }
    }

    if ($type === 'array' && isset($schema['items'])) {
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...schemaErrors($schema['items'], $item, "{$path}[{$index}]")];
        }
    }

    return $errors;
}
