<?php

use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;

test('a stored UTC value reads in the institution time zone with its offset', function () {
    $berlin = new InstitutionTime('Europe/Berlin');

    expect(InstitutionTime::iso($berlin->fromUtc('2026-10-16 21:59:59')))->toBe('2026-10-16T23:59:59+02:00')
        ->and(InstitutionTime::iso($berlin->fromUtc('2026-12-16 22:59:59')))->toBe('2026-12-16T23:59:59+01:00')
        ->and(InstitutionTime::iso($berlin->in(CarbonImmutable::parse('2026-10-09T08:00:00Z'))))->toBe('2026-10-09T10:00:00+02:00')
        ->and($berlin->fromUtc(null))->toBeNull();
});

test('each stored value is converted once', function () {
    $berlin = new InstitutionTime('Europe/Berlin');

    expect($berlin->fromUtc('2026-10-16 21:59:59'))->toBe($berlin->fromUtc('2026-10-16 21:59:59'));
});
