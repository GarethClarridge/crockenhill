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
];
