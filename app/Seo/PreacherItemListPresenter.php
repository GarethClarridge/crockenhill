<?php

declare(strict_types=1);

namespace App\Seo;

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
        $orgName = (string) config('church.name');
        $appUrl = (string) config('app.url');
        $orgId = $appUrl.'/#organization';

        $worksFor = [
            '@type' => 'Organization',
            'name' => $orgName,
            '@id' => $orgId,
        ];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $preachers->count(),
            'itemListElement' => $preachers->map(function ($preacher, $index) use ($worksFor) {
                $preacherUrl = route('sermons.preacher', ['preacher' => $preacher->slug]);
                $item = [
                    '@type' => 'Person',
                    '@id' => $preacherUrl.'#person',
                    'name' => $preacher->name,
                    'url' => $preacherUrl,
                    'jobTitle' => 'Preacher',
                    'worksFor' => $worksFor,
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
