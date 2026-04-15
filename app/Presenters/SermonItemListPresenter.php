<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;

class SermonItemListPresenter
{
    private const DEFAULT_AUDIO_MIME_TYPE = 'audio/mpeg';

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

        $orgName = (string) config('organization.name');
        $logoUrl = asset('images/Primary.png');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $flatSermons->count(),
            'itemListElement' => $flatSermons->values()->map(fn (Sermon $sermon, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => $this->serializeSermon($sermon, $orgName, $logoUrl),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSermon(Sermon $sermon, string $orgName, string $logoUrl): array
    {
        $view = $this->sermonViewPresenter->present($sermon);
        $canonicalUrl = $view['canonical_url'];
        $thumbnailUrl = $view['thumbnail_url'] ?: $logoUrl;
        $metaDescription = $this->sermonViewPresenter->metaDescription($sermon);
        $datePublished = $sermon->date->toIso8601String();
        $duration = $sermon->duration ? CarbonInterval::seconds((int) $sermon->duration)->cascade()->spec() : null;

        $item = [
            '@type' => 'Article',
            'headline' => $sermon->title,
            'name' => $sermon->title,
            'url' => $canonicalUrl,
            'description' => $metaDescription,
            'datePublished' => $datePublished,
            'inLanguage' => 'en-GB',
            'contentLocation' => [
                '@type' => 'Place',
                'name' => $orgName,
            ],
            'author' => [
                '@type' => 'Person',
                'name' => $this->sermonViewPresenter->displayPreacherName($sermon),
                'url' => $view['preacher_url'],
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $orgName,
                '@id' => url('/'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                ],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonicalUrl,
            ],
            'image' => $thumbnailUrl,
        ];

        if ($view['video_url'] !== null) {
            $item['video'] = [
                '@type' => 'VideoObject',
                'name' => $sermon->title,
                'description' => $metaDescription,
                'thumbnailUrl' => $thumbnailUrl,
                'uploadDate' => $datePublished,
                'contentUrl' => $view['video_url'],
            ];

            if ($duration !== null) {
                $item['video']['duration'] = $duration;
            }
        }

        if ($view['audio_url'] !== null) {
            $item['audio'] = [
                '@type' => 'AudioObject',
                'name' => $sermon->title,
                'contentUrl' => $view['audio_url'],
                'description' => $metaDescription,
                'encodingFormat' => self::DEFAULT_AUDIO_MIME_TYPE,
                'uploadDate' => $datePublished,
            ];

            if ($duration !== null) {
                $item['audio']['duration'] = $duration;
            }
        }

        return $item;
    }
}
