<?php

use App\Enums\ContentType;
use App\Models\ContentItem;

use function Pest\Laravel\getJson;

test('lists the library ordered by topic, title, then id', function () {
    $heartFailure = ContentItem::factory()->create(['title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => ContentType::QuestionSet, 'estimated_minutes' => 30]);
    $asthma = ContentItem::factory()->create(['title' => 'Asthma Basics', 'topic' => 'Pulmonology', 'type' => ContentType::Article, 'estimated_minutes' => 15]);
    $ecg = ContentItem::factory()->create(['title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => ContentType::Article, 'estimated_minutes' => 20]);
    $secondEcg = ContentItem::factory()->create(['title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => ContentType::Article, 'estimated_minutes' => 25]);

    actingAsEducator();

    getJson('/api/v1/content')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                ['id' => $ecg->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'estimated_minutes' => 20],
                ['id' => $secondEcg->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'estimated_minutes' => 25],
                ['id' => $heartFailure->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'estimated_minutes' => 30],
                ['id' => $asthma->id, 'title' => 'Asthma Basics', 'topic' => 'Pulmonology', 'type' => 'article', 'estimated_minutes' => 15],
            ],
            'meta' => ['next_cursor' => null],
        ]);
});

test('topic matches exactly and search matches part of the title, both case-insensitive', function (array $query, array $titles) {
    ContentItem::factory()->create(['title' => 'ECG Basics', 'topic' => 'Cardiology']);
    ContentItem::factory()->create(['title' => 'Heart Failure Question Set', 'topic' => 'Cardiology']);
    ContentItem::factory()->create(['title' => 'Paediatric ECG', 'topic' => 'Pediatric Cardiology']);
    ContentItem::factory()->create(['title' => 'Asthma Basics', 'topic' => 'Pulmonology']);

    actingAsEducator();

    expect(getJson('/api/v1/content?'.http_build_query($query))->assertOk()->json('data.*.title'))->toBe($titles);
})->with([
    'topic cardiology' => [['topic' => 'cardiology'], ['ECG Basics', 'Heart Failure Question Set']],
    'search ecg' => [['search' => 'ecg'], ['ECG Basics', 'Paediatric ECG']],
    'topic and search' => [['topic' => 'CARDIOLOGY', 'search' => 'ecg'], ['ECG Basics']],
    'a wildcard character is literal' => [['search' => '_'], []],
]);

test('pages with limit and cursor', function () {
    $first = ContentItem::factory()->create(['title' => 'A', 'topic' => 'Cardiology']);
    $second = ContentItem::factory()->create(['title' => 'B', 'topic' => 'Cardiology']);

    actingAsEducator();

    $firstPage = getJson('/api/v1/content?limit=1')->assertOk()->assertJsonPath('data.0.id', $first->id);

    getJson('/api/v1/content?'.http_build_query(['limit' => 1, 'cursor' => $firstPage->json('meta.next_cursor')]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('meta.next_cursor', null);
});
