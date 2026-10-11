<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

test('adds the new learners, lists the existing members and writes one audit row per added learner', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $member = learnerIn($group);
    $zoe = User::factory()->learner()->create(['institution_id' => $group->institution_id, 'name' => 'Zoe Adams']);
    $priya = User::factory()->learner()->create(['institution_id' => $group->institution_id, 'name' => 'Priya Nair']);

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/learners", ['user_ids' => [$zoe->id, $member->id, $priya->id]])
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'added' => [
                    ['id' => $priya->id, 'name' => 'Priya Nair'],
                    ['id' => $zoe->id, 'name' => 'Zoe Adams'],
                ],
                'already_members' => [
                    ['id' => $member->id, 'name' => $member->name],
                ],
            ],
        ]);

    $audits = AuditEntry::query()->orderBy('id')->get();

    expect(DB::table('group_learners')->where('group_id', $group->id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$member->id, $zoe->id, $priya->id])->sort()->values()->all())
        ->and($audits)->toHaveCount(2)
        ->and($audits->pluck('changes')->sortBy('user_id')->values()->all())
        ->toBe(collect([['user_id' => $zoe->id], ['user_id' => $priya->id]])->sortBy('user_id')->values()->all());

    foreach ($audits as $audit) {
        expect($audit->action)->toBe(AuditAction::GroupLearnerAdded)
            ->and($audit->channel)->toBe(AuditChannel::Rest)
            ->and($audit->actor_id)->toBe($educator->id)
            ->and($audit->group_id)->toBe($group->id)
            ->and($audit->subject_type)->toBe('group')
            ->and($audit->subject_id)->toBe($group->id);
    }
});

test('the same call again answers 200 with everyone under already_members and writes no audit row', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $learners = User::factory()->learner()->count(2)->create(['institution_id' => $group->institution_id]);
    $body = ['user_ids' => $learners->modelKeys()];

    actingAsEducator($educator);
    postJson("/api/v1/groups/{$group->id}/learners", $body)->assertOk();
    $auditCount = AuditEntry::query()->count();

    postJson("/api/v1/groups/{$group->id}/learners", $body)
        ->assertOk()
        ->assertJsonPath('data.added', [])
        ->assertJsonCount(2, 'data.already_members');

    expect(AuditEntry::query()->count())->toBe($auditCount)
        ->and(DB::table('group_learners')->where('group_id', $group->id)->count())->toBe(2);
});

test('an id that is not a learner of the caller\'s institution answers 422 on user_ids.N and writes nothing', function (Closure $makeUserId) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $valid = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    $invalidId = $makeUserId($group);

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/learners", ['user_ids' => [$valid->id, $invalidId]])
        ->assertUnprocessable()
        ->assertExactJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'details' => ['user_ids.1' => ["User {$invalidId} is not a learner of your institution."]],
            ],
        ]);

    expect(DB::table('group_learners')->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'a learner of another institution' => fn (Group $group) => User::factory()->learner()->create()->id,
    'an educator of the same institution' => fn (Group $group) => User::factory()->educator()->create(['institution_id' => $group->institution_id])->id,
    'an id that does not exist' => fn (Group $group) => (int) User::query()->max('id') + 1000,
]);

test('user_ids that are not 1 to 200 distinct integers answer 422 and write nothing', function (Closure $makeBody) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $learner = User::factory()->learner()->create(['institution_id' => $group->institution_id]);

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/learners", $makeBody($learner))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(DB::table('group_learners')->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'a duplicate id' => fn (User $learner) => ['user_ids' => [$learner->id, $learner->id]],
    'missing' => fn (User $learner) => [],
    'empty' => fn (User $learner) => ['user_ids' => []],
    'not integers' => fn (User $learner) => ['user_ids' => ['abc']],
    'more than 200' => fn (User $learner) => ['user_ids' => range($learner->id, $learner->id + 200)],
]);
