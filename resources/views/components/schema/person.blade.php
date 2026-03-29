@props([
    'person',
])

@php
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $person->name,
        'url' => url("/christ/sermons/preachers/{$person->slug}"),
        'worksFor' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            'url' => url('/'),
        ],
    ];

    if ($person->bio) {
        $schema['description'] = $person->meta_description;
    }

    if ($person->profile_image_url) {
        $schema['image'] = $person->profile_image_url;
    }
@endphp

<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
