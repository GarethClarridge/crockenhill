<?php

declare(strict_types=1);

namespace App\Seo;

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
        $appUrl = (string) config('app.url');
        $orgId = $appUrl.'/#organization';
        $orgName = (string) config('church.name');
        $logoUrl = asset('images/Primary.png');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $series->count(),
            'itemListElement' => $series->map(function ($seriesName, $index) use ($orgId, $orgName, $logoUrl) {
                $seriesUrl = route('sermons.series.show', ['series' => Str::slug($seriesName)]);

                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'CreativeWorkSeries',
                        '@id' => $seriesUrl.'#series',
                        'name' => $seriesName,
                        'url' => $seriesUrl,
                        'inLanguage' => 'en-GB',
                        'publisher' => [
                            '@type' => 'Organization',
                            'name' => $orgName,
                            '@id' => $orgId,
                            'logo' => [
                                '@type' => 'ImageObject',
                                'url' => $logoUrl,
                                'width' => 444,
                                'height' => 481,
                            ],
                        ],
                    ],
                ];
            })->values()->all(),
        ];
    }
}
