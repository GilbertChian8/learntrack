<?php

use App\Enums\AssignmentScope;
use App\Enums\ProgressStatus;
use App\Enums\Role;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('every factory and state creates a valid row', function () {
    $institution = Institution::factory()->create();
    $educator = User::factory()->educator()->create();
    $learners = User::factory()->learner()->count(2)->create();
    $group = Group::factory()->create();
    $content = ContentItem::factory()->create();
    $assignment = Assignment::factory()->create();
    $subset = Assignment::factory()->forLearners($learners->all())->create();
    $removed = Assignment::factory()->removed()->create();
    $started = Progress::factory()->create();
    $completed = Progress::factory()->completed(72)->create();
    $audit = AuditEntry::factory()->create();

    foreach ([$institution, $educator, $group, $content, $assignment, $subset, $removed, $started, $completed, $audit] as $model) {
        expect($model->newQuery()->whereKey($model->getKey())->exists())->toBeTrue();
    }

    expect($educator->role)->toBe(Role::Educator)
        ->and($learners->every(fn (User $learner) => $learner->role === Role::Learner))->toBeTrue()
        ->and($subset->scope)->toBe(AssignmentScope::Learners)
        ->and(DB::table('assignment_learners')->where('assignment_id', $subset->id)->pluck('user_id')->sort()->values()->all())
        ->toBe($learners->pluck('id')->sort()->values()->all())
        ->and($removed->removed_at)->not->toBeNull()
        ->and($started->status)->toBe(ProgressStatus::InProgress)
        ->and($started->completed_at)->toBeNull()
        ->and($completed->status)->toBe(ProgressStatus::Completed)
        ->and($completed->score)->toBe(72)
        ->and($completed->completed_at)->not->toBeNull();
});
