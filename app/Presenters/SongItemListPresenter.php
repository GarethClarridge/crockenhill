<?php

declare(strict_types=1);

namespace App\Presenters;

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
        /** @var list<array<string, mixed>> $itemListElements */
        $itemListElements = $songs->values()->map(function (Song $song, int $index): array {
            /** @var list<array<string, string>> $authors */
            $authors = $song->authors->map(fn ($author): array => [
                '@type' => 'Person',
                'name' => (string) $author->display_name,
            ])->values()->all();

            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'MusicComposition',
                    'name' => $song->title,
                    'url' => route('church.songs.show', $song->slug),
                    'author' => $authors,
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
