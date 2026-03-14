<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Preacher;
use Illuminate\Support\Collection;

class PreacherItemListPresenter
{
    /**
     * Convert a collection of preachers into a Schema.org ItemList data array.
     *
     * @param  Collection<int, Preacher>  $preachers
     * @return array<string, mixed>
     */
    public function toItemList(Collection $preachers): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $preachers->count(),
            'itemListElement' => $preachers->map(function ($preacher, $index) {
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Person',
                        'name' => $preacher->name,
                        'url' => url("/christ/sermons/preachers/{$preacher->slug}"),
                        'jobTitle' => 'Preacher',
                        'worksFor' => [
                            '@type' => 'Organization',
                            'name' => config('organization.name'),
                        ],
                    ],
                ];
            })->values()->all(),
        ];
    }
}
