<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use Illuminate\Support\Collection;

class SermonItemListPresenter
{
    /**
     * Convert a collection of sermons into a Schema.org ItemList data array.
     *
     * @param  Collection<string, Collection<int, Sermon>>|Collection<int, Sermon>  $sermons
     * @return array<string, mixed>
     */
    public function toItemList(Collection $sermons): array
    {
        /** @var Collection<int, Sermon> $flatSermons */
        $flatSermons = $sermons->first() instanceof Collection
            ? $sermons->flatten()
            : $sermons;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $flatSermons->count(),
            'itemListElement' => $flatSermons->values()->map(function (Sermon $sermon, int $index) {
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'CreativeWork',
                        'name' => $sermon->title,
                        'url' => $sermon->public_url,
                        'description' => $sermon->meta_description,
                        'datePublished' => $sermon->date->toIso8601String(),
                        'author' => [
                            '@type' => 'Person',
                            'name' => $sermon->preacherProfile->name ?? $sermon->preacher,
                        ],
                    ],
                ];
            })->all(),
        ];
    }
}
