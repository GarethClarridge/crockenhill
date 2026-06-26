<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\Song;
use Illuminate\Support\Collection;

class SongItemListPresenter
{
    /**
     * @param  Collection<int, Song>  $songs
     * @return array<string, mixed>
     */
    public function toItemList(Collection $songs): array
    {
        $orgName = (string) config('organization.name');
        $logoUrl = asset('images/Primary.png');
        $appUrl = (string) config('app.url');
        $orgId = $appUrl.'/#organization';

        $publisher = [
            '@type' => 'Church',
            'name' => $orgName,
            '@id' => $orgId,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
                'width' => 444,
                'height' => 481,
            ],
        ];

        /** @var list<array<string, mixed>> $itemListElements */
        $itemListElements = $songs->values()->map(function (Song $song, int $index) use ($publisher): array {
            /** @var list<array<string, string>> $authors */
            $authors = $song->authors->map(fn ($author): array => [
                '@type' => 'Person',
                'name' => (string) $author->display_name,
            ])->values()->all();

            $songUrl = route('church.songs.show', $song->slug);

            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'MusicComposition',
                    '@id' => $songUrl.'#song',
                    'name' => $song->title,
                    'url' => $songUrl,
                    'author' => $authors,
                    'publisher' => $publisher,
                ],
            ];
        })->values()->all();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $songs->count(),
            'itemListElement' => $itemListElements,
        ];
    }
}
