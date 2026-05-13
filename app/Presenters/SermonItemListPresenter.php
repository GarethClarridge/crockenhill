<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use Illuminate\Support\Collection;

class SermonItemListPresenter
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memoizedAuthors = [];

    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Convert a collection of sermons into a Schema.org ItemList data array.
     *
     * Performance Optimization: Utilizes bulk presentation to pre-calculate
     * display data for all sermons in the collection, reducing redundant
     * logic and enabling efficient reuse of presenter results.
     *
     * @param  Collection<string, Collection<int, Sermon>>|Collection<int, Sermon>  $sermons
     * @return array<string, mixed>
     */
    public function toItemList(Collection $sermons): array
    {
        /** @var Collection<int, Sermon> $flatSermons */
        $flatSermons = $sermons->flatten(1);

        // Populate memoization caches for all sermons in one pass
        $this->sermonViewPresenter->presentCollection($flatSermons);

        $orgName = (string) config('organization.name');
        $logoUrl = asset('images/Primary.png');
        $appUrl = (string) config('app.url');
        $appId = $appUrl.'/';

        $publisher = [
            '@type' => 'Organization',
            'name' => $orgName,
            '@id' => $appId,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
            ],
        ];

        $contentLocation = [
            '@type' => 'Place',
            'name' => $orgName,
        ];

        $worksFor = [
            '@type' => 'Organization',
            'name' => $orgName,
            '@id' => $appId,
        ];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $flatSermons->count(),
            'itemListElement' => $flatSermons->values()->map(function (Sermon $sermon, int $index) use ($logoUrl, $publisher, $contentLocation, $worksFor) {
                $sermonView = $this->sermonViewPresenter->presentForList($sermon);
                $thumbnailUrl = $sermonView['thumbnail_url'];
                $publicUrl = $sermonView['public_url'];
                $datePublished = $sermon->date->toIso8601String();
                $metaDescription = $this->sermonViewPresenter->metaDescription($sermon);

                $preacherKey = $sermon->preacher_id !== null
                    ? "id_{$sermon->preacher_id}"
                    : (string) $sermonView['preacher_name'];

                /** @var array<string, mixed> $author */
                $author = $this->memoizedAuthors[$preacherKey] ??= [
                    '@type' => 'Person',
                    'name' => $sermonView['preacher_name'],
                    'url' => $sermonView['preacher_url'],
                    'jobTitle' => 'Preacher',
                    'worksFor' => $worksFor,
                ];

                if ($sermonView['preacher_image_url'] && ! isset($author['image'])) {
                    $author['image'] = $sermonView['preacher_image_url'];
                    $this->memoizedAuthors[$preacherKey] = $author;
                }

                $item = [
                    '@type' => 'Article',
                    'headline' => $sermon->title,
                    'name' => $sermon->title,
                    'url' => $publicUrl,
                    'description' => $metaDescription,
                    'datePublished' => $datePublished,
                    'dateModified' => $sermon->updated_at?->toIso8601String() ?? $datePublished,
                    'inLanguage' => 'en-GB',
                    'contentLocation' => $contentLocation,
                    'author' => $author,
                    'publisher' => $publisher,
                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',
                        '@id' => $publicUrl,
                    ],
                    'image' => $thumbnailUrl ?: $logoUrl,
                ];

                if ($sermonView['video_url']) {
                    $video = [
                        '@type' => 'VideoObject',
                        'name' => $sermon->title,
                        'description' => $metaDescription,
                        'thumbnailUrl' => $thumbnailUrl ?: $logoUrl,
                        'uploadDate' => $datePublished,
                        'contentUrl' => $sermonView['video_url'],
                    ];

                    if ($sermonView['duration_iso8601']) {
                        $video['duration'] = $sermonView['duration_iso8601'];
                    }

                    $item['video'] = $video;
                }

                if ($sermonView['audio_url']) {
                    $audio = [
                        '@type' => 'AudioObject',
                        'name' => $sermon->title,
                        'contentUrl' => $sermonView['audio_url'],
                        'description' => $metaDescription,
                        'encodingFormat' => 'audio/mpeg',
                        'uploadDate' => $datePublished,
                    ];

                    if ($sermonView['duration_iso8601']) {
                        $audio['duration'] = $sermonView['duration_iso8601'];
                    }

                    $item['audio'] = $audio;
                }

                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => $item,
                ];
            })->all(),
        ];
    }
}
