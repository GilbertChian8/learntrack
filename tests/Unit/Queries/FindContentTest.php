<?php

use App\Models\ContentItem;
use App\Queries\FindContent;
use Illuminate\Support\Facades\DB;

test('byId finds an item of the whole library, or nothing', function () {
    $item = ContentItem::factory()->create();

    expect(FindContent::byId($item->id)?->is($item))->toBeTrue()
        ->and(FindContent::byId($item->id + 1))->toBeNull();
});

test('byTitle matches anywhere in the title, case-insensitive, ordered by title then id, in one query', function () {
    $sounds = ContentItem::factory()->create(['title' => 'Heart Sounds']);
    $failure = ContentItem::factory()->create(['title' => 'Heart Failure Question Set']);
    $congenital = ContentItem::factory()->create(['title' => 'Congenital Heart Disease']);
    $secondSounds = ContentItem::factory()->create(['title' => 'Heart Sounds']);
    ContentItem::factory()->create(['title' => 'ECG Basics']);

    DB::enableQueryLog();

    expect(FindContent::byTitle('HEART')->modelKeys())->toBe([$congenital->id, $failure->id, $sounds->id, $secondSounds->id])
        ->and(DB::getQueryLog())->toHaveCount(1);
});

test('byTitle reads % and _ as plain characters', function () {
    $percent = ContentItem::factory()->create(['title' => 'Dosing at 50% Strength']);
    ContentItem::factory()->create(['title' => 'Dosing at Full Strength']);
    $underscore = ContentItem::factory()->create(['title' => 'Lab_Values']);
    ContentItem::factory()->create(['title' => 'Lab Values']);

    expect(FindContent::byTitle('50%')->modelKeys())->toBe([$percent->id])
        ->and(FindContent::byTitle('b_v')->modelKeys())->toBe([$underscore->id])
        ->and(FindContent::byTitle('no such title'))->toBeEmpty();
});
