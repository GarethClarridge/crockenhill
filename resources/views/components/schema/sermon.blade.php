@props([
    'sermon',
    'sermonView',
    'metaDescription',
])

@php
    use Illuminate\Support\Str;

    $transcript = $sermonView['transcript'] ?? null;
    $duration = $sermonView['duration_iso8601'] ?? null;
    $preacherName = $sermonView['preacher_name'];
    $thumbnailUrl = $sermonView['thumbnail_url'] ?: asset('images/Primary.png');
    $datePublished = $sermon->date->toIso8601String();

    $author = [
        '@type' => 'Person',
        'name' => $preacherName,
        'url' => $sermonView['preacher_url'],
        'jobTitle' => 'Preacher',
        'worksFor' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            '@id' => config('app.url').'/',
        ],
    ];

    if ($sermonView['preacher_image_url']) {
        $author['image'] = $sermonView['preacher_image_url'];
    }

    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $sermon->title,
        'description' => $metaDescription,
        'image' => $thumbnailUrl,
        'datePublished' => $datePublished,
        'author' => $author,
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            '@id' => config('app.url').'/',
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
