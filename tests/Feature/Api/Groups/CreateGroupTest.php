<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\Institution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

test('creates the group in the caller\'s institution with the caller as its educator and one audit row', function () {
    CarbonImmutable::setTestNow('2026-10-12 08:00:00');
    $institution = Institution::factory()->create(['name' => 'Northside Medical School', 'timezone' => 'Europe/Berlin']);
    $educator = actingAsEducator(User::factory()->educator()->create(['institution_id' => $institution->id]));

    $response = postJson('/api/v1/groups', ['name' => 'Cardiology Group B']);

    $group = Group::query()->sole();

    $response->assertCreated()
        ->assertExactJson([
            'data' => [
                'id' => $group->id,
                'name' => 'Cardiology Group B',
                'institution' => ['id' => $institution->id, 'name' => 'Northside Medical School'],
                'learner_count' => 0,
                'assignment_count' => 0,
                'created_at' => '2026-10-12T10:00:00+02:00',
            ],
        ]);

    $audit = AuditEntry::query()->sole();

    expect($group->institution_id)->toBe($institution->id)
        ->and(DB::table('group_educators')->where('group_id', $group->id)->pluck('user_id')->all())->toBe([$educator->id])
        ->and($audit->action)->toBe(AuditAction::GroupCreated)
        ->and($audit->channel)->toBe(AuditChannel::Rest)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->group_id)->toBe($group->id)
        ->and($audit->subject_type)->toBe('group')
        ->and($audit->subject_id)->toBe($group->id)
        ->and($audit->changes)->toBe(['name' => 'Cardiology Group B']);
});

test('a missing, empty or 256-character name answers 422 and writes nothing', function (array $body) {
    actingAsEducator();

    postJson('/api/v1/groups', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors(['name'], 'error.details');

    expect(Group::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'missing' => [[]],
    'empty' => [['name' => '']],
    '256 characters' => [['name' => str_repeat('a', 256)]],
]);
