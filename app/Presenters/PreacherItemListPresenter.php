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
                $item = [
                    '@type' => 'Person',
                    'name' => $preacher->name,
                    'url' => url("/christ/sermons/preachers/{$preacher->slug}"),
                    'jobTitle' => 'Preacher',
                    'worksFor' => [
                        '@type' => 'Organization',
                        'name' => $orgName,
                        '@id' => config('app.url').'/',
                    ],
                ];

                if ($preacher->profile_image_url) {
                    $item['image'] = $preacher->profile_image_url;
                }

                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => $item,
                ];
            })->values()->all(),
        ];
    }
}
