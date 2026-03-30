<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PodcastFeedItemReadModel;
use App\Enums\SermonService;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PodcastFeedService
{
    public function __construct(
        private readonly SermonStorageService $storageService,
        private readonly SermonViewPresenter $sermonViewPresenter,
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
            return Cache::flexible(
                $cacheKey,
                [$cacheConfig['ttl'], $cacheConfig['stale_ttl']],
                fn () => $this->fetchSermons($serviceType)
            );
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

        return Sermon::query()
            ->whereSermon()
            ->forPodcast()
            ->select(['id', 'title', 'audio_file_path', 'filetype', 'date', 'service', 'series', 'reference', 'preacher', 'preacher_id', 'duration', 'summary', 'slug', 'thumbnail_file_path', 'thumbnail_generated_at', 'transcript_file_path', 'updated_at', 'scripture_passage_id'])
            ->with([
                'preacherProfile:id,name,slug',
                'scripturePassage:id,display_reference,normalized_reference',
            ])
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
            enclosureUrl: $this->storageService->getPublicUrl($sermon),
            episodeImageUrl: $this->sermonViewPresenter->thumbnailUrl($sermon),
            itunesDuration: $this->formatItunesDuration((int) ($sermon->duration ?? 0)),
            podcastSummary: $this->buildPodcastSummary($sermon),
            publishedAt: $sermon->date->toRfc2822String(),
            sermonId: $sermon->id,
            title: $sermon->title,
            transcriptUrl: $this->buildTranscriptUrl($sermon),
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

        $reference = $sermon->displayReference();
        if ($reference !== null) {
            $parts[] = "A sermon on {$reference}";
        }

        $preacherName = $sermon->displayPreacherName();
        if ($preacherName) {
            $prefix = empty($parts) ? 'A sermon from' : 'from';
            $parts[] = "{$prefix} {$preacherName}";
        }

        if ($sermon->series) {
            $parts[] = "as part of our {$sermon->series} series";
        }

        return ! empty($parts) ? implode(' ', $parts).'.' : $sermon->title;
    }

    private function buildTranscriptUrl(Sermon $sermon): ?string
    {
        if (! $sermon->transcript_file_path) {
            return null;
        }

        return url("/christ/sermons/{$sermon->date->format('Y')}/{$sermon->date->format('m')}/{$sermon->slug}/transcript");
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

    /**
     * Clear feed cache
     */
    public function clearCache(?string $serviceType = null): void
    {
        if ($serviceType) {
            Cache::forget("podcast_feed_{$serviceType}");
        } else {
            Cache::forget('podcast_feed_morning');
            Cache::forget('podcast_feed_evening');
        }
    }
}
