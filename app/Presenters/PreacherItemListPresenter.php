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
        $orgName = (string) config('organization.name');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $preachers->count(),
            'itemListElement' => $preachers->map(function ($preacher, $index) use ($orgName) {
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Person',
                        'name' => $preacher->name,
                        'url' => url("/christ/sermons/preachers/{$preacher->slug}"),
                        'image' => $preacher->profile_image_url,
                        'jobTitle' => 'Preacher',
                        'worksFor' => [
                            '@type' => 'Organization',
                            'name' => $orgName,
                        ],
                    ],
                ];
            })->values()->all(),
        ];
    }
}
