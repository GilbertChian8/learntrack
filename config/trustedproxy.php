<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated addresses or CIDRs whose X-Forwarded-For, -Proto and
    | -Port headers are trusted. Laravel's TrustProxies middleware reads it.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
