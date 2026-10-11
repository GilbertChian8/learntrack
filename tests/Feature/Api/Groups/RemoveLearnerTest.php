<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;

test('removes the learner, keeps their progress rows and writes one audit row; a repeat writes none', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $learner = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create();
    $progress = Progress::factory()->completed()->create(['assignment_id' => $assignment->id, 'user_id' => $learner->id]);

    actingAsEducator($educator);

    deleteJson("/api/v1/groups/{$group->id}/learners/{$learner->id}")->assertNoContent();

    $audit = AuditEntry::query()->sole();

    expect(DB::table('group_learners')->where('group_id', $group->id)->where('user_id', $learner->id)->exists())->toBeFalse()
        ->and(Progress::query()->whereKey($progress->id)->exists())->toBeTrue()
        ->and($audit->action)->toBe(AuditAction::GroupLearnerRemoved)
        ->and($audit->channel)->toBe(AuditChannel::Rest)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->group_id)->toBe($group->id)
        ->and($audit->subject_type)->toBe('group')
        ->and($audit->subject_id)->toBe($group->id)
        ->and($audit->changes)->toBe(['user_id' => $learner->id]);

    deleteJson("/api/v1/groups/{$group->id}/learners/{$learner->id}")->assertNoContent();

    expect(AuditEntry::query()->count())->toBe(1);
});

test('a user who is not a member answers 204 and writes nothing', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $member = learnerIn($group);
    $stranger = User::factory()->learner()->create(['institution_id' => $group->institution_id]);

    actingAsEducator($educator);

    deleteJson("/api/v1/groups/{$group->id}/learners/{$stranger->id}")->assertNoContent();
    deleteJson("/api/v1/groups/{$group->id}/learners/99999999999999999999")->assertNoContent();

    expect(DB::table('group_learners')->where('group_id', $group->id)->pluck('user_id')->all())->toBe([$member->id])
        ->and(AuditEntry::query()->count())->toBe(0);
});
