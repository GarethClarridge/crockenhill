<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use Illuminate\Support\Collection;

class SermonItemListPresenter
{
    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Convert a collection of sermons into a Schema.org ItemList data array.
     *
     * @param  Collection<string, Collection<int, Sermon>>|Collection<int, Sermon>  $sermons
     * @return array<string, mixed>
     */
    public function toItemList(Collection $sermons): array
    {
        /** @var Collection<int, Sermon> $flatSermons */
        $flatSermons = $sermons->flatten(1);

        $orgName = (string) config('organization.name');
        $logoUrl = asset('images/Primary.png');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $flatSermons->count(),
            'itemListElement' => $flatSermons->values()->map(function (Sermon $sermon, int $index) use ($orgName, $logoUrl) {
                $sermonView = $this->sermonViewPresenter->present($sermon);
                $thumbnailUrl = $sermonView['thumbnail_url'];
                $publicUrl = $sermonView['public_url'];
                $datePublished = $sermon->date->toIso8601String();
                $metaDescription = $this->sermonViewPresenter->metaDescription($sermon);

                $author = [
                    '@type' => 'Person',
                    'name' => $sermonView['preacher_name'],
                    'url' => $sermonView['preacher_url'],
                    'jobTitle' => 'Preacher',
                    'worksFor' => [
                        '@type' => 'Organization',
                        'name' => $orgName,
                        '@id' => config('app.url').'/',
                    ],
                ];

                if ($sermonView['preacher_image_url']) {
                    $author['image'] = $sermonView['preacher_image_url'];
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
                    'contentLocation' => [
                        '@type' => 'Place',
                        'name' => $orgName,
                    ],
                    'author' => $author,
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $orgName,
                        '@id' => config('app.url').'/',
                        'logo' => [
                            '@type' => 'ImageObject',
                            'url' => $logoUrl,
                        ],
                    ],
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
