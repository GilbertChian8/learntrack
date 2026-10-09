<?php

use App\Models\Group;
use Illuminate\Database\LazyLoadingViolationException;

test('reading an unloaded relationship throws outside production', function () {
    Group::factory()->count(2)->create();

    $groups = Group::query()->get();

    expect(fn () => $groups->first()?->institution)
        ->toThrow(LazyLoadingViolationException::class);

    $eagerLoaded = Group::query()->with('institution')->get();

    expect($eagerLoaded->first()?->institution)->not->toBeNull();
});
