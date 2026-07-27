{{-- Organization Schema.org JSON-LD markup for SEO --}}
@php
    $openingHours = [];
    foreach (config('church.opening_hours', []) as $day => $slots) {
        $schemaDay = "https://schema.org/".ucfirst($day);
        foreach ($slots as $slot) {
            $openingHours[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => [$schemaDay],
                'opens' => $slot['opens'],
                'closes' => $slot['closes'],
            ];
        }
    }

    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => config('app.url').'/#organization',
                'name' => config('church.name'),
                'url' => config('app.url'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset('images/Primary.png'),
                    'width' => 444,
                    'height' => 481,
                ],
                'description' => config('church.description'),
                'taxID' => config('church.charity_number'),
                'telephone' => config('church.phone'),
                'email' => config('church.email_admin'),
                'contactPoint' => [
                    '@type' => 'ContactPoint',
                    'telephone' => config('church.phone'),
                    'contactType' => 'administrative',
                    'email' => config('church.email_admin'),
                ],
                'sameAs' => array_values(config('church.social')),
            ],
            [
                '@type' => 'Church',
                '@id' => config('app.url').'/#church',
                'name' => config('church.name'),
                'url' => config('app.url'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset('images/Primary.png'),
                    'width' => 444,
                    'height' => 481,
                ],
                'description' => config('church.description'),
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => config('church.address.street'),
                    'addressLocality' => config('church.address.locality'),
                    'addressRegion' => config('church.address.region'),
                    'postalCode' => config('church.address.postal_code'),
                    'addressCountry' => config('church.address.country'),
                ],
                'geo' => [
                    '@type' => 'GeoCoordinates',
                    'latitude' => config('church.geo.latitude'),
                    'longitude' => config('church.geo.longitude'),
                ],
                'telephone' => config('church.phone'),
                'email' => config('church.email_admin'),
                'parentOrganization' => [
                    '@id' => config('app.url').'/#organization',
                ],
            ],
        ],
    ];

    if (!empty($openingHours)) {
        $schema['@graph'][1]['openingHoursSpecification'] = $openingHours;
    }
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
