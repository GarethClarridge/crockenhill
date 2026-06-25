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
    $lastModified = ($sermon->updated_at instanceof \Carbon\CarbonInterface && $sermon->updated_at->year > 0)
        ? $sermon->updated_at->toIso8601String()
        : $datePublished;

    $author = [
        '@type' => 'Person',
        '@id' => $sermonView['preacher_url'] . '#person',
        'name' => $preacherName,
        'url' => $sermonView['preacher_url'],
        'jobTitle' => 'Preacher',
        'worksFor' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            'url' => config('app.url'),
            '@id' => config('app.url').'/#organization',
        ],
    ];

    if ($sermonView['preacher_image_url']) {
        $author['image'] = $sermonView['preacher_image_url'];
    }

    $articleBody = $transcript ?: (($sermon->show_summary && $sermon->summary) ? strip_tags($sermon->summary) : null);

    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'Article',
        '@id' => $sermonView['canonical_url'] . '#sermon',
        'url' => $sermonView['canonical_url'],
        'headline' => $sermon->title,
        'description' => $metaDescription,
        'articleBody' => $articleBody,
        'image' => $thumbnailUrl,
        'datePublished' => $datePublished,
        'dateModified' => $lastModified,
        'inLanguage' => 'en-GB',
        'genre' => 'Sermon',
        'author' => $author,
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            'url' => config('app.url'),
            '@id' => config('app.url').'/#organization',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/Primary.png'),
                'width' => 444,
                'height' => 481,
            ],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $sermonView['canonical_url'] . '#webpage',
        ],
        'speakable' => [
            '@type' => 'SpeakableSpecification',
            'xpath' => [
                '/html/head/title',
                '/html/head/meta[@name="description"]/@content',
            ],
        ],
    ];

    if ($sermon->series) {
        $schema['keywords'] = $sermon->series;
        $schema['isPartOf'] = [
            '@type' => 'CreativeWorkSeries',
            'name' => $sermon->series,
            'url' => $sermonView['series_url'],
            '@id' => $sermonView['series_url'] . '#series',
            'inLanguage' => 'en-GB',
        ];
    }

    if ($displayReference = $sermonView['display_reference']) {
        $schema['about'] = [
            '@type' => 'Thing',
            'name' => $displayReference,
        ];
    }

    if ($sermonView['video_url']) {
        $schema['video'] = [
            '@type' => 'VideoObject',
            'name' => $sermon->title,
            'url' => $sermonView['canonical_url'],
            'description' => $metaDescription,
            'thumbnailUrl' => $thumbnailUrl,
            'uploadDate' => $datePublished,
            'contentUrl' => $sermonView['video_url'],
            'inLanguage' => 'en-GB',
            'author' => $author,
            'publisher' => $schema['publisher'],
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
            'url' => $sermonView['canonical_url'],
            'contentUrl' => $sermonView['audio_url'],
            'description' => $metaDescription,
            'encodingFormat' => 'audio/mpeg',
            'uploadDate' => $datePublished,
            'inLanguage' => 'en-GB',
            'author' => $author,
            'publisher' => $schema['publisher'],
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
