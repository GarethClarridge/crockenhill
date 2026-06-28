@props([
    'preacher',
])

@php
    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'Person',
        '@id' => url("/christ/sermons/preachers/{$preacher->slug}").'#person',
        'name' => $preacher->name,
        'url' => url("/christ/sermons/preachers/{$preacher->slug}"),
        'worksFor' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            '@id' => config('app.url').'/#organization',
        ],
    ];

    if ($preacher->bio) {
        $schema['description'] = $preacher->bio;
    }

    if ($preacher->profile_image_url) {
        $schema['image'] = $preacher->profile_image_url;
    }
@endphp

<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
