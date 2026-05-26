<?php

declare(strict_types=1);

namespace App\Presenters;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SeriesItemListPresenter
{
    /**
     * Convert a collection of series names into a Schema.org ItemList data array.
     *
     * @param  Collection<int, string>  $series
     * @return array<string, mixed>
     */
    public function toItemList(Collection $series): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $series->count(),
            'itemListElement' => $series->map(function ($seriesName, $index) {
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'CreativeWorkSeries',
                        'name' => $seriesName,
                        'url' => route('sermons.series.show', ['series' => Str::slug($seriesName)]),
                    ],
                ];
            })->values()->all(),
        ];
    }
}
