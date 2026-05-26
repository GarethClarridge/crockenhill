<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Visual Regression Determinism
    |--------------------------------------------------------------------------
    |
    | These knobs are only consulted by AppServiceProvider when set. They make
    | the test environment reproducible for Playwright visual regression so
    | snapshot diffs do not flake on seeded data variance or clock drift.
    |
    */

    'seed' => env('PLAYWRIGHT_SEED'),

    'frozen_now' => env('PLAYWRIGHT_NOW'),
];
