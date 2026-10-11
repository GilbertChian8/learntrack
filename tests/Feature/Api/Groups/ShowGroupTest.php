<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\Institution;
use App\Models\User;

use function Pest\Laravel\getJson;

test('shows the group with its educators and learners ordered by name then id', function () {
    $institution = Institution::factory()->create(['name' => 'Northside Medical School']);
    $group = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Cardiology Group A', 'created_at' => '2026-09-01 07:00:00']);
    $jonas = User::factory()->educator()->create(['institution_id' => $institution->id, 'name' => 'Jonas Weber']);
    $anna = User::factory()->educator()->create(['institution_id' => $institution->id, 'name' => 'Anna Keller']);
    $tom = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Tom Becker']);
    $priya = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Priya Nair']);
    $sara = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Sara Lindqvist']);
    $secondPriya = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Priya Nair']);
    $group->educators()->attach([$jonas->id, $anna->id]);
    $group->learners()->attach([$tom->id, $priya->id, $sara->id, $secondPriya->id]);
    Assignment::factory()->for($group)->create();
    Assignment::factory()->for($group)->removed()->create();

    actingAsEducator($anna);

    getJson("/api/v1/groups/{$group->id}")
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $group->id,
                'name' => 'Cardiology Group A',
                'institution' => ['id' => $institution->id, 'name' => 'Northside Medical School'],
                'learner_count' => 4,
                'assignment_count' => 1,
                'educators' => [
                    ['id' => $anna->id, 'name' => 'Anna Keller'],
                    ['id' => $jonas->id, 'name' => 'Jonas Weber'],
                ],
                'learners' => [
                    ['id' => $priya->id, 'name' => 'Priya Nair'],
                    ['id' => $secondPriya->id, 'name' => 'Priya Nair'],
                    ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
                    ['id' => $tom->id, 'name' => 'Tom Becker'],
                ],
                'created_at' => '2026-09-01T07:00:00+00:00',
            ],
        ]);
});
