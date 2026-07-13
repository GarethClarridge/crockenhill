{{-- WebPage Schema.org JSON-LD markup for SEO --}}
@props([
    'heading',
    'description' => null,
    'image' => null,
    'canonical' => null,
    'mainEntity' => null,
    'datePublished' => null,
    'dateModified' => null,
    'includeBreadcrumb' => true,
])

@php
    $pageUrl = $canonical ?? url()->current();
    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'WebPage',
        '@id' => $pageUrl.'#webpage',
        'url' => $pageUrl,
        'name' => $heading,
        'description' => $description ?? $heading,
        'inLanguage' => 'en-GB',
        'isPartOf' => [
            '@type' => 'WebSite',
            '@id' => config('app.url').'/#website',
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('church.name'),
            '@id' => config('app.url').'/#organization',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/Primary.png'),
                'width' => 444,
                'height' => 481,
            ],
        ],
        'speakable' => [
            '@type' => 'SpeakableSpecification',
            'xpath' => [
                '/html/head/title',
                '/html/head/meta[@name="description"]/@content',
            ],
        ],
    ];

    if ($includeBreadcrumb) {
        $schema['breadcrumb'] = [
            '@type' => 'BreadcrumbList',
            '@id' => $pageUrl.'#breadcrumb',
        ];
    }

    if ($datePublished) {
        $schema['datePublished'] = $datePublished instanceof \DateTimeInterface
            ? $datePublished->toIso8601String()
            : $datePublished;
    }

    if ($dateModified) {
        $schema['dateModified'] = $dateModified instanceof \DateTimeInterface
            ? $dateModified->toIso8601String()
            : $dateModified;
    }

    if ($image) {
        $schema['image'] = $image;
    }

    if ($mainEntity) {
        $schema['mainEntity'] = [
            '@id' => $mainEntity,
        ];
    }
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
