<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PodcastFeedService
{
    public function __construct(
        private SermonStorageService $storageService
    ) {}

    /**
     * Get sermons for a specific service type feed
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsForFeed(string $serviceType): Collection
    {
        $cacheKey = "podcast_feed_{$serviceType}";

        /** @var array{enabled: bool, ttl: int, stale_ttl: int}|null $cacheConfig */
        $cacheConfig = config('podcast.cache');

        // Handle missing config gracefully (e.g., config not yet cached in production)
        if ($cacheConfig === null) {
            return $this->fetchSermons($serviceType);
        }

        if ($cacheConfig['enabled']) {
            /** @var Collection<int, Sermon> */
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
     * @return Collection<int, Sermon>
     */
    private function fetchSermons(string $serviceType): Collection
    {
        /** @var int $limit */
        $limit = config('podcast.items_limit', 100);

        return Sermon::forPodcast()
            ->select(['id', 'title', 'audio_file_path', 'filetype', 'date', 'service', 'series', 'reference', 'preacher', 'preacher_id', 'duration', 'summary', 'slug', 'thumbnail_file_path', 'transcript_file_path'])
            ->with('preacherProfile:id,name,slug')
            ->forService($serviceType)
            ->limit($limit)
            ->get()
            ->map(fn (Sermon $sermon) => $this->enrichSermonForFeed($sermon));
    }

    /**
     * Enrich sermon with computed values for RSS feed
     */
    private function enrichSermonForFeed(Sermon $sermon): Sermon
    {
        $sermon->setAttribute('enclosure_url', $this->storageService->getPublicUrl($sermon));
        $sermon->setAttribute('enclosure_length', $this->storageService->getFileSize($sermon) ?? 0);
        $sermon->setAttribute('itunes_duration', $this->formatItunesDuration((int) ($sermon->duration ?? 0)));
        $sermon->setAttribute('podcast_summary', $this->buildPodcastSummary($sermon));
        $sermon->setAttribute('rss_pub_date', $sermon->date->toRfc2822String());
        $sermon->setAttribute('episode_image_url', $sermon->thumbnail_url);
        $sermon->setAttribute('transcript_url', $this->buildTranscriptUrl($sermon));

        return $sermon;
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

        if ($sermon->reference) {
            $parts[] = "A sermon on {$sermon->reference}";
        }

        $preacherName = ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile)
            ? $sermon->preacherProfile->name
            : $sermon->preacher;
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
        // Default values in case config is not yet cached
        // podcast_guid is a permanent UUID for each feed - generate once and keep forever
        $defaults = [
            'morning' => [
                'title' => 'Sunday mornings at Crockenhill Baptist Church',
                'description' => 'Sermons from Sunday mornings at Crockenhill Baptist Church',
                'image' => '/images/podcast/MorningArtwork.webp',
                'route' => '/christ/sermons/morning',
                'podcast_guid' => 'cbc-morning-sermons-a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            ],
            'evening' => [
                'title' => 'Sunday evenings at Crockenhill Baptist Church',
                'description' => 'Sermons from Sunday evenings at Crockenhill Baptist Church',
                'image' => '/images/podcast/EveningArtwork.webp',
                'route' => '/christ/sermons/evening',
                'podcast_guid' => 'cbc-evening-sermons-f9e8d7c6-b5a4-3210-fedc-ba0987654321',
            ],
        ];

        /** @var array{title: string, description: string, image: string, route: string}|null $feedConfig */
        $feedConfig = config("podcast.feeds.{$serviceType}");

        // Fall back to defaults if config not loaded
        if ($feedConfig === null) {
            $feedConfig = $defaults[$serviceType] ?? $defaults['morning'];
        }

        // Get podcast_guid from config or defaults
        $defaultFeed = $defaults[$serviceType] ?? $defaults['morning'];
        $podcastGuid = (string) config("podcast.feeds.{$serviceType}.podcast_guid", $defaultFeed['podcast_guid']);

        return [
            'title' => $feedConfig['title'],
            'description' => $feedConfig['description'],
            'link' => url($feedConfig['route']),
            'image' => url($feedConfig['image']),
            'feed_url' => url("{$feedConfig['route']}/feed"),
            'owner_name' => (string) config('podcast.owner.name', 'Crockenhill Baptist Church'),
            'owner_email' => (string) config('podcast.owner.email', 'admin@crockenhill.org'),
            'author' => (string) config('podcast.author', 'Crockenhill Baptist Church'),
            'language' => (string) config('podcast.language', 'en-gb'),
            'category' => (string) config('podcast.category', 'Religion & Spirituality'),
            'subcategory' => (string) config('podcast.subcategory', 'Christianity'),
            'explicit' => (string) config('podcast.explicit', 'no'),
            'podcast_guid' => $podcastGuid,
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
