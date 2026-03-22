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

                $item = [
                    '@type' => 'CreativeWork',
                    'name' => $sermon->title,
                    'url' => $this->sermonViewPresenter->publicUrl($sermon),
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
                ];

                if ($thumbnailUrl !== null) {
                    $item['image'] = $thumbnailUrl;
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
