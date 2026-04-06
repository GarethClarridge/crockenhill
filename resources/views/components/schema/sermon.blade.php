@props([
    'sermon',
    'sermonView',
])

@php
    use Illuminate\Support\Str;

    $transcript = $sermonView['transcript'] ?? null;
    $duration = $sermon->duration ? \Carbon\CarbonInterval::seconds($sermon->duration)->cascade()->spec() : null;
    $preacherName = $sermon->displayPreacherName();
    $thumbnailUrl = $sermonView['thumbnail_url'] ?: asset('images/Primary.png');
    $datePublished = $sermon->date->toIso8601String();
    // Sermon::getMetaDescriptionAttribute() is the single visibility gate for summary-derived description text.
    $metaDescription = $sermon->meta_description;

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $sermon->title,
        'description' => $metaDescription,
        'image' => $thumbnailUrl,
        'datePublished' => $datePublished,
        'author' => [
            '@type' => 'Person',
            'name' => $preacherName,
            'url' => $sermonView['preacher_url'],
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            '@id' => config('app.url'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/Primary.png'),
            ],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $sermonView['canonical_url'],
        ],
    ];

    if ($sermonView['video_url']) {
        $schema['video'] = [
            '@type' => 'VideoObject',
            'name' => $sermon->title,
            'description' => $metaDescription,
            'thumbnailUrl' => $thumbnailUrl,
            'uploadDate' => $datePublished,
            'contentUrl' => $sermonView['video_url'],
        ];

        if ($duration) {
            $schema['video']['duration'] = $duration;
        }

        if ($transcript) {
            $schema['video']['transcript'] = $transcript;
        }
    }

    if ($sermonView['audio_url']) {
        $schema['audio'] = [
            '@type' => 'AudioObject',
            'name' => $sermon->title,
            'contentUrl' => $sermonView['audio_url'],
            'description' => $metaDescription,
            'encodingFormat' => 'audio/mpeg',
            'uploadDate' => $datePublished,
        ];

        if ($duration) {
            $schema['audio']['duration'] = $duration;
        }

        if ($transcript) {
            $schema['audio']['transcript'] = $transcript;
        }
    }
@endphp

<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
