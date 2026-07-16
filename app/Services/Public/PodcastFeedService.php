<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Data\PodcastFeedItemReadModel;
use App\Enums\SermonService;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Sermon\SermonStorageService;
use App\Support\FlexibleCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PodcastFeedService
{
    public function __construct(
        private readonly SermonStorageService $storageService,
        private readonly SermonViewPresenter $sermonViewPresenter,
        private readonly SermonRepository $sermonRepository,
    ) {}

    /**
     * Get sermons for a specific service type feed
     *
     * @return Collection<int, PodcastFeedItemReadModel>
     */
    public function getSermonsForFeed(SermonService $serviceType): Collection
    {
        $cacheKey = "podcast_feed_{$serviceType->value}";

        /** @var array{enabled: bool, ttl: int, stale_ttl: int} $cacheConfig */
        $cacheConfig = config('podcast.cache');

        if ($cacheConfig['enabled']) {
            /** @var Collection<int, PodcastFeedItemReadModel> */
            $feed = Cache::flexible(
                $cacheKey,
                [$cacheConfig['ttl'], $cacheConfig['stale_ttl']],
                fn () => $this->fetchSermons($serviceType)
            );

            $zeroLengthSermonIds = $feed
                ->filter(fn (PodcastFeedItemReadModel $item): bool => $item->enclosureLength === 0)
                ->map(fn (PodcastFeedItemReadModel $item): int => $item->sermonId)
                ->values();

            if ($zeroLengthSermonIds->isNotEmpty()) {
                // A zero enclosure length is either a transient storage failure
                // (heals on the next request) or an audio file that is genuinely
                // missing — the latter defeats this cache on every request until
                // the sermon is fixed, so make it visible.
                Log::warning('Podcast feed contains zero-length enclosures; feed cache invalidated', [
                    'feed' => $cacheKey,
                    'sermon_ids' => $zeroLengthSermonIds->all(),
                ]);

                FlexibleCache::forget($cacheKey);
            }

            return $feed;
        }

        return $this->fetchSermons($serviceType);
    }

    /**
     * Fetch sermons from database and enrich for feed.
     *
     * Performance Optimization: Limits retrieved columns to required fields for the RSS feed,
     * excluding very large text fields (like full transcripts or points) while keeping summary for descriptions.
     * Eager loads 'preacherProfile' with restricted columns to prevent N+1 queries during enrichment.
     *
     * @return Collection<int, PodcastFeedItemReadModel>
     */
    private function fetchSermons(SermonService $serviceType): Collection
    {
        /** @var int $limit */
        $limit = config('podcast.items_limit', 100);

        return $this->sermonRepository
            ->publicSermonQuery()
            ->forPodcast()
            ->forService($serviceType)
            ->limit($limit)
            ->get()
            ->map(fn (Sermon $sermon) => $this->enrichSermonForFeed($sermon));
    }

    /**
     * Enrich sermon with computed values for RSS feed
     */
    private function enrichSermonForFeed(Sermon $sermon): PodcastFeedItemReadModel
    {
        return new PodcastFeedItemReadModel(
            canonicalUrl: $this->sermonViewPresenter->canonicalUrl($sermon),
            enclosureLength: $this->storageService->getFileSize($sermon) ?? 0,
            enclosureUrl: (string) $this->storageService->getAudioDeliveryUrl($sermon),
            episodeImageUrl: $this->sermonViewPresenter->thumbnailUrl($sermon),
            itunesDuration: $this->formatItunesDuration((int) ($sermon->duration ?? 0)),
            podcastSummary: $this->buildPodcastSummary($sermon),
            publishedAt: $sermon->date->toRfc2822String(),
            sermonId: $sermon->id,
            title: $sermon->title,
            transcriptUrl: $this->sermonViewPresenter->transcriptUrl($sermon),
        );
    }

    private function formatItunesDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    private function buildPodcastSummary(Sermon $sermon): string
    {
        $parts = [];

        $reference = $this->sermonViewPresenter->displayReference($sermon);
        if ($reference !== null) {
            $parts[] = "A sermon on {$reference}";
        }

        $preacherName = $this->sermonViewPresenter->displayPreacherName($sermon);
        if ($preacherName) {
            $prefix = empty($parts) ? 'A sermon from' : 'from';
            $parts[] = "{$prefix} {$preacherName}";
        }

        if ($sermon->series) {
            $parts[] = "as part of our {$sermon->series} series";
        }

        return ! empty($parts) ? implode(' ', $parts).'.' : $sermon->title;
    }

    /**
     * Get feed metadata for a service type
     *
     * @return array<string, string>
     */
    public function getFeedMetadata(string $serviceType): array
    {
        /** @var array{title: string, description: string, image: string, route: string, podcast_guid: string} $feedConfig */
        $feedConfig = config("podcast.feeds.{$serviceType}");

        return [
            'title' => $feedConfig['title'],
            'description' => $feedConfig['description'],
            'link' => url($feedConfig['route']),
            'image' => url($feedConfig['image']),
            'feed_url' => url("{$feedConfig['route']}/feed"),
            'owner_name' => (string) config('podcast.owner.name'),
            'owner_email' => (string) config('podcast.owner.email'),
            'author' => (string) config('podcast.author'),
            'language' => (string) config('podcast.language'),
            'category' => (string) config('podcast.category'),
            'subcategory' => (string) config('podcast.subcategory'),
            'explicit' => (string) config('podcast.explicit'),
            'podcast_guid' => $feedConfig['podcast_guid'],
        ];
    }
}
