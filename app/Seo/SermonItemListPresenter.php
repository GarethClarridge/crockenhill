<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SermonItemListPresenter
{
    /**
     * Memoization for presented data arrays and collections.
     *
     * @var array<string, array<string, mixed>|array<int, array<string, mixed>>>
     */
    private array $memoizedPresents = [];

    /**
     * Tracks which keys have been computed, allowing null to be a legitimate cached result.
     *
     * @var array<string, true>
     */
    private array $computed = [];

    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Clear all internal memoization caches.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPresents = [];
        $this->computed = [];
    }

    /**
     * Convert a collection of sermons into a Schema.org ItemList data array.
     *
     * Performance Optimization: Utilizes bulk presentation to pre-calculate
     * display data for all sermons in the collection, reducing redundant
     * logic and enabling efficient reuse of presenter results.
     *
     * @param  Collection<string, Collection<int, Sermon>>|Collection<int, Sermon>  $sermons
     * @return array<string, mixed>
     */
    public function toItemList(Collection $sermons): array
    {
        /** @var Collection<int, Sermon> $flatSermons */
        $flatSermons = $sermons->flatten(1);

        $orgName = (string) config('church.name');
        $logoUrl = asset('images/Primary.png');
        $appUrl = (string) config('app.url');
        $orgId = $appUrl.'/#organization';

        $publisher = $this->buildPublisher($orgName, $logoUrl, $orgId);
        $contentLocation = $this->buildContentLocation($orgName, $orgId);
        $worksFor = $this->buildWorksFor($orgName, $orgId);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => $flatSermons->count(),
            'itemListElement' => $flatSermons->values()->map(fn (Sermon $sermon, int $index): array => $this->buildListItem(
                $sermon,
                $index,
                $logoUrl,
                $publisher,
                $contentLocation,
                $worksFor
            ))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublisher(string $orgName, string $logoUrl, string $orgId): array
    {
        return [
            '@type' => 'Organization',
            'name' => $orgName,
            '@id' => $orgId,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
                'width' => 444,
                'height' => 481,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContentLocation(string $orgName, string $orgId): array
    {
        return [
            '@type' => 'Place',
            'name' => $orgName,
            '@id' => $orgId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorksFor(string $orgName, string $orgId): array
    {
        return [
            '@type' => 'Organization',
            'name' => $orgName,
            '@id' => $orgId,
        ];
    }

    /**
     * @param  array<string, mixed>  $sermonView
     * @param  array<string, mixed>  $worksFor
     * @return array<string, mixed>
     */
    private function resolveAuthor(Sermon $sermon, array $sermonView, array $worksFor): array
    {
        $preacherKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermonView['preacher_name'];

        $authorKey = "author_{$preacherKey}";

        if (isset($this->computed[$authorKey])) {
            /** @var array<string, mixed> */
            return $this->memoizedPresents[$authorKey];
        }

        $author = [
            '@type' => 'Person',
            '@id' => $sermonView['preacher_url'].'#person',
            'name' => $sermonView['preacher_name'],
            'url' => $sermonView['preacher_url'],
            'jobTitle' => 'Preacher',
            'worksFor' => $worksFor,
        ];

        if ($sermonView['preacher_image_url']) {
            $author['image'] = $sermonView['preacher_image_url'];
        }

        $this->computed[$authorKey] = true;

        return $this->memoizedPresents[$authorKey] = $author;
    }

    /**
     * @param  array<string, mixed>  $sermonView
     * @param  array<string, mixed>  $author
     * @param  array<string, mixed>  $publisher
     * @param  array<string, mixed>  $contentLocation
     * @return array<string, mixed>
     */
    private function buildArticle(
        Sermon $sermon,
        array $sermonView,
        string $datePublished,
        string $metaDescription,
        array $author,
        array $publisher,
        array $contentLocation,
        string $logoUrl,
        ?string $articleBody = null,
    ): array {
        $lastModified = ($sermon->updated_at instanceof Carbon && $sermon->updated_at->year > 0)
            ? $sermon->updated_at->toIso8601String()
            : $datePublished;

        $article = [
            '@type' => 'Article',
            '@id' => $sermonView['canonical_url'].'#sermon',
            'headline' => $sermon->title,
            'name' => $sermon->title,
            'url' => $sermonView['canonical_url'],
            'description' => $metaDescription,
            'articleBody' => $articleBody,
            'datePublished' => $datePublished,
            'dateModified' => $lastModified,
            'inLanguage' => 'en-GB',
            'genre' => 'Sermon',
            'articleSection' => 'Sermons',
            'contentLocation' => $contentLocation,
            'author' => $author,
            'publisher' => $publisher,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $sermonView['canonical_url'].'#webpage',
            ],
            'image' => $sermonView['thumbnail_url'] ?: $logoUrl,
        ];

        $keywords = array_filter([
            $sermon->series,
            $sermonView['preacher_name'],
            $sermonView['display_reference'],
        ]);

        if ($keywords !== []) {
            $article['keywords'] = implode(', ', $keywords);
        }

        if ($sermon->series) {
            $article['isPartOf'] = [
                '@type' => 'CreativeWorkSeries',
                'name' => $sermon->series,
                'url' => $sermonView['series_url'],
                '@id' => $sermonView['series_url'].'#series',
            ];
        }

        if ($displayReference = $sermonView['display_reference']) {
            $article['about'] = [
                '@type' => 'Thing',
                'name' => $displayReference,
            ];
        }

        return $article;
    }

    /**
     * @param  array<string, mixed>  $sermonView
     * @param  array<string, mixed>  $author
     * @param  array<string, mixed>  $publisher
     * @return array<string, mixed>
     */
    private function buildVideoObject(
        Sermon $sermon,
        array $sermonView,
        string $datePublished,
        string $metaDescription,
        string $logoUrl,
        array $author,
        array $publisher,
    ): array {
        $video = [
            '@type' => 'VideoObject',
            'name' => $sermon->title,
            'description' => $metaDescription,
            'thumbnailUrl' => $sermonView['thumbnail_url'] ?: $logoUrl,
            'uploadDate' => $datePublished,
            'contentUrl' => $sermonView['video_url'],
            'author' => $author,
            'publisher' => $publisher,
        ];

        if ($sermonView['duration_iso8601']) {
            $video['duration'] = $sermonView['duration_iso8601'];
        }

        return $video;
    }

    /**
     * @param  array<string, mixed>  $sermonView
     * @param  array<string, mixed>  $author
     * @param  array<string, mixed>  $publisher
     * @return array<string, mixed>
     */
    private function buildAudioObject(
        Sermon $sermon,
        array $sermonView,
        string $datePublished,
        string $metaDescription,
        string $logoUrl,
        array $author,
        array $publisher,
    ): array {
        $audio = [
            '@type' => 'AudioObject',
            'name' => $sermon->title,
            'description' => $metaDescription,
            'thumbnailUrl' => $sermonView['thumbnail_url'] ?: $logoUrl,
            'contentUrl' => $sermonView['audio_url'],
            'encodingFormat' => 'audio/mpeg',
            'uploadDate' => $datePublished,
            'author' => $author,
            'publisher' => $publisher,
        ];

        if ($sermonView['duration_iso8601']) {
            $audio['duration'] = $sermonView['duration_iso8601'];
        }

        return $audio;
    }

    /**
     * @param  array<string, mixed>  $publisher
     * @param  array<string, mixed>  $contentLocation
     * @param  array<string, mixed>  $worksFor
     * @return array<string, mixed>
     */
    private function buildListItem(
        Sermon $sermon,
        int $index,
        string $logoUrl,
        array $publisher,
        array $contentLocation,
        array $worksFor
    ): array {
        $sermonView = $this->sermonViewPresenter->presentForList($sermon);
        $datePublished = $sermon->date->toIso8601String();
        $metaDescription = $this->sermonViewPresenter->metaDescription($sermon);

        $author = $this->resolveAuthor($sermon, $sermonView, $worksFor);

        $articleBody = ($sermon->show_summary && $sermon->summary) ? strip_tags($sermon->summary) : null;

        $item = $this->buildArticle(
            $sermon,
            $sermonView,
            $datePublished,
            $metaDescription,
            $author,
            $publisher,
            $contentLocation,
            $logoUrl,
            $articleBody
        );

        if ($sermonView['video_url']) {
            $item['video'] = $this->buildVideoObject(
                $sermon,
                $sermonView,
                $datePublished,
                $metaDescription,
                $logoUrl,
                $author,
                $publisher,
            );
        }

        if ($sermonView['audio_url']) {
            $item['audio'] = $this->buildAudioObject(
                $sermon,
                $sermonView,
                $datePublished,
                $metaDescription,
                $logoUrl,
                $author,
                $publisher,
            );
        }

        return [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'item' => $item,
        ];
    }
}
