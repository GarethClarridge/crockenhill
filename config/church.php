<?php

return [
    'name' => 'Crockenhill Baptist Church',
    'description' => 'An independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, and proclaiming Jesus Christ to all.',
    'phone' => '+44-1322-663995',
    'phone_display' => '01322 663995',
    'email_admin' => 'admin@crockenhill.org',
    'email_public' => 'pastor@crockenhill.org',
    'charity_number' => '1199873',
    'address' => [
        'street' => 'Eynsford Road',
        'locality' => 'Crockenhill',
        'region' => 'Kent',
        'postal_code' => 'BR8 8JS',
        'country' => 'GB',
    ],
    'geo' => [
        'latitude' => '51.38349261524606',
        'longitude' => '0.16404725602797054',
    ],
    'social' => [
        'facebook' => 'https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905',
        'youtube' => 'https://www.youtube.com/@crockenhillbaptistchurch9727/streams',
    ],
    'opening_hours' => [
        'sunday' => [
            [
                'opens' => '10:30',
                'closes' => '12:00',
                'description' => 'Sunday Morning Service',
            ],
            [
                'opens' => '18:00',
                'closes' => '19:15',
                'description' => 'Sunday Evening Service',
            ],
        ],
    ],
    'sermons' => [
        'childrens_talks' => [
            'public' => env('CHILDRENS_TALKS_PUBLIC', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public service history
    |--------------------------------------------------------------------------
    |
    | `public_from` is the era boundary for the public service archive. Services
    | dated before it are complete in the database but are never listed, served
    | or indexed.
    |
    | This exists because the historic import will land a decade of services in
    | one operation, and the editorial, copyright, speaker-consent and takedown
    | questions covering historic material are open (see WP8 §14.4 of
    | docs/plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md). The
    | sermon-level exposure attributes withhold *media*; nothing else withheld a
    | *service* from the listing. This does.
    |
    | Null means no lower bound. Set it before the import runs.
    |
    */
    'services' => [
        'public_from' => env('CHURCH_SERVICES_PUBLIC_FROM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Historic corpus size
    |--------------------------------------------------------------------------
    |
    | The number of services the approved corpus manifest covers. The §9.4.6
    | review-load gate reconciles the staged and projected counts against it, so
    | that an empty proposal census cannot be mistaken for a converged one when
    | the truth is that nothing has been staged yet.
    |
    | Null means no corpus has been approved, and the gate refuses to pass.
    |
    */
    'historic_corpus' => [
        'expected_services' => env('HISTORIC_CORPUS_EXPECTED_SERVICES'),

        /*
        | The approved G8 production-import operation identifier.
        |
        | The plan forbids canonical historic imports against production until
        | G8. That prohibition used to live only in the plan's prose, which made
        | it simultaneously unenforceable and over-broad: nothing stopped an
        | operator pointing `--import` at production, while a literal reading
        | also blocked the *local* evidence staging §13.5 steps 3-4 require —
        | and local staging is the only route to G5.
        |
        | So the scope is now mechanical. Outside production the guard is
        | silent, because a rehearsal database is where this work belongs. In
        | production it fails closed until this names the approved operation,
        | which the closeout report then quotes as the authority the run had.
        |
        | Null means production imports are unapproved. That is the correct
        | resting state; set it only for the duration of an approved window.
        */
        'production_import_approval' => env('HISTORIC_IMPORT_PRODUCTION_APPROVAL'),
    ],
];
