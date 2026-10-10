<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Support\Scope;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->group(function () {
        Route::get('api/v1/test/groups/{id}', fn (#[CurrentUser] User $user, string $id) => Scope::group($user, (int) $id)->id);
        Route::get('api/v1/test/assignments/{id}', fn (#[CurrentUser] User $user, string $id) => Scope::assignment($user, (int) $id)->id);
    });
});

test('Scope::group returns a group the educator teaches', function () {
    $group = Group::factory()->create();

    expect(Scope::group(educatorOf($group), $group->id)->is($group))->toBeTrue();
});

test('Scope::group throws the same NotFoundException for a missing id and another educator\'s group', function () {
    $group = Group::factory()->create();
    educatorOf($group);
    $otherEducator = User::factory()->educator()->create(['institution_id' => $group->institution_id]);
    $missingId = $group->id + 1000;

    expect(notFoundSubject(fn () => Scope::group($otherEducator, $group->id)))->toBe('Group')
        ->and(notFoundSubject(fn () => Scope::group($otherEducator, $missingId)))->toBe('Group');

    actingAsEducator($otherEducator);
    $foreign = getJson("/api/v1/test/groups/{$group->id}");
    $missing = getJson("/api/v1/test/groups/{$missingId}");

    $foreign->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);

    expect($missing->status())->toBe(404)
        ->and($missing->getContent())->toBe($foreign->getContent());
});

test('Scope::assignment throws the same NotFoundException for a removed assignment and a missing id', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $active = Assignment::factory()->for($group)->create();
    $removed = Assignment::factory()->for($group)->removed()->create();
    $missingId = $removed->id + 1000;

    expect(Scope::assignment($educator, $active->id)->is($active))->toBeTrue()
        ->and(notFoundSubject(fn () => Scope::assignment($educator, $removed->id)))->toBe('Assignment')
        ->and(notFoundSubject(fn () => Scope::assignment($educator, $missingId)))->toBe('Assignment');

    actingAsEducator($educator);
    $removedResponse = getJson("/api/v1/test/assignments/{$removed->id}");
    $missingResponse = getJson("/api/v1/test/assignments/{$missingId}");

    $removedResponse->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);

    expect($missingResponse->status())->toBe(404)
        ->and($missingResponse->getContent())->toBe($removedResponse->getContent());
});

test('Scope::learnerAssignment returns an assignment that targets the learner and hides the rest', function () {
    $group = Group::factory()->create();
    $learner = learnerIn($group);
    $targeting = Assignment::factory()->for($group)->create();
    $foreign = Assignment::factory()->create();

    expect(Scope::learnerAssignment($learner, $targeting->id)->is($targeting))->toBeTrue()
        ->and(notFoundSubject(fn () => Scope::learnerAssignment($learner, $foreign->id)))->toBe('Assignment');
});
