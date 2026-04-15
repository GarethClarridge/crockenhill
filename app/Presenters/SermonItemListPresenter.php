<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use Carbon\CarbonInterval;
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
                $thumbnailUrl = $this->sermonViewPresenter->thumbnailUrl($sermon);
                $publicUrl = $this->sermonViewPresenter->publicUrl($sermon);
                $videoUrl = $this->sermonViewPresenter->videoUrl($sermon);
                $audioUrl = $this->sermonViewPresenter->audioUrl($sermon);
                $metaDescription = $this->sermonViewPresenter->metaDescription($sermon);
                $datePublished = $sermon->date->toIso8601String();
                $duration = $sermon->duration ? CarbonInterval::seconds($sermon->duration)->cascade()->spec() : null;

                $item = [
                    '@type' => 'Article',
                    'headline' => $sermon->title,
                    'name' => $sermon->title,
                    'url' => $publicUrl,
                    'description' => $metaDescription,
                    'datePublished' => $datePublished,
                    'inLanguage' => 'en-GB',
                    'contentLocation' => [
                        '@type' => 'Place',
                        'name' => $orgName,
                    ],
                    'author' => [
                        '@type' => 'Person',
                        'name' => $this->sermonViewPresenter->displayPreacherName($sermon),
                        'url' => $this->sermonViewPresenter->preacherUrl($sermon),
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $orgName,
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

                if ($videoUrl) {
                    $item['video'] = [
                        '@type' => 'VideoObject',
                        'name' => $sermon->title,
                        'description' => $metaDescription,
                        'thumbnailUrl' => $thumbnailUrl ?: $logoUrl,
                        'uploadDate' => $datePublished,
                        'contentUrl' => $videoUrl,
                    ];

                    if ($duration) {
                        $item['video']['duration'] = $duration;
                    }
                }

                if ($audioUrl) {
                    $item['audio'] = [
                        '@type' => 'AudioObject',
                        'name' => $sermon->title,
                        'contentUrl' => $audioUrl,
                        'description' => $metaDescription,
                        'encodingFormat' => 'audio/mpeg',
                        'uploadDate' => $datePublished,
                    ];

                    if ($duration) {
                        $item['audio']['duration'] = $duration;
                    }
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
