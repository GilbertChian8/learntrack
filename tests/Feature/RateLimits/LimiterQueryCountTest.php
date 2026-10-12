<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('the limiter adds eight cache queries to the first request of a window and six to each later one', function () {
    actingAsEducator();

    $queries = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        getJson('/api/v1/groups')->assertOk();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->countBy(fn (array $query) => str_contains($query['query'], '`cache`') ? 'limiter' : 'endpoint')
            ->all();
    };

    expect($queries())->toBe(['limiter' => 8, 'endpoint' => 1])
        ->and($queries())->toBe(['limiter' => 6, 'endpoint' => 1])
        ->and($queries())->toBe(['limiter' => 6, 'endpoint' => 1]);
});

test('a 429 costs the limiter three cache queries and the endpoint none', function () {
    foreach (range(1, 10) as $request) {
        postJson('/oauth/register', ['redirect_uris' => []]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    postJson('/oauth/register', ['redirect_uris' => []])->assertTooManyRequests();
    DB::disableQueryLog();

    expect(collect(DB::getQueryLog())->every(fn (array $query) => str_contains($query['query'], '`cache`')))->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(3);
});
