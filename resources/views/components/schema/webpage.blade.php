{{-- WebPage Schema.org JSON-LD markup for SEO --}}
@props([
    'heading',
    'description' => null,
    'image' => null,
    'canonical' => null,
])

@php
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $heading,
        'description' => $description ?? $heading,
        'url' => $canonical ?? url()->current(),
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            '@id' => config('app.url').'/',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/Primary.png'),
            ],
        ],
    ];

    if ($image) {
        $schema['image'] = $image;
    }
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
