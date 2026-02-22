<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Safe Markdown Options
    |--------------------------------------------------------------------------
    |
    | Shared CommonMark options used for rendering user-managed markdown that
    | will be output as HTML in Blade views.
    |
    */
    'safe_options' => [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ],
];
