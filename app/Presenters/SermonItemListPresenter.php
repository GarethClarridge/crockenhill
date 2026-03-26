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

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $flatSermons->count(),
            'itemListElement' => $flatSermons->values()->map(function (Sermon $sermon, int $index) {
                $thumbnailUrl = $this->sermonViewPresenter->thumbnailUrl($sermon);
                $publicUrl = $this->sermonViewPresenter->publicUrl($sermon);

                $item = [
                    '@type' => 'Article',
                    'headline' => $sermon->title,
                    'name' => $sermon->title,
                    'url' => $publicUrl,
                    'description' => $sermon->meta_description,
                    'datePublished' => $sermon->date->toIso8601String(),
                    'inLanguage' => 'en-GB',
                    'contentLocation' => [
                        '@type' => 'Place',
                        'name' => config('organization.name'),
                    ],
                    'author' => [
                        '@type' => 'Person',
                        'name' => $sermon->displayPreacherName(),
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => config('organization.name'),
                        'logo' => [
                            '@type' => 'ImageObject',
                            'url' => asset('images/Primary.png'),
                        ],
                    ],
                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',
                        '@id' => $publicUrl,
                    ],
                    'image' => $thumbnailUrl ?: asset('images/Primary.png'),
                ];

                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => $item,
                ];
            })->all(),
        ];
    }
}
