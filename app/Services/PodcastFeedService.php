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

        /** @var array{enabled: bool, ttl: int, stale_ttl: int} $cacheConfig */
        $cacheConfig = config('podcast.cache');

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
     * Fetch sermons from database and enrich for feed
     *
     * @return Collection<int, Sermon>
     */
    private function fetchSermons(string $serviceType): Collection
    {
        /** @var int $limit */
        $limit = config('podcast.items_limit', 100);

        return Sermon::forPodcast()
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
        // Attach computed values for feed
        $sermon->setAttribute('enclosure_url', $this->storageService->getPublicUrl($sermon));
        $sermon->setAttribute('enclosure_length', $this->storageService->getFileSize($sermon) ?? 0);

        return $sermon;
    }

    /**
     * Get feed metadata for a service type
     *
     * @return array<string, string>
     */
    public function getFeedMetadata(string $serviceType): array
    {
        /** @var array{title: string, description: string, image: string, route: string} $feedConfig */
        $feedConfig = config("podcast.feeds.{$serviceType}");

        /** @var string $ownerName */
        $ownerName = config('podcast.owner.name');
        /** @var string $ownerEmail */
        $ownerEmail = config('podcast.owner.email');
        /** @var string $author */
        $author = config('podcast.author');
        /** @var string $language */
        $language = config('podcast.language');
        /** @var string $category */
        $category = config('podcast.category');
        /** @var string $subcategory */
        $subcategory = config('podcast.subcategory');
        /** @var string $explicit */
        $explicit = config('podcast.explicit');

        return [
            'title' => $feedConfig['title'],
            'description' => $feedConfig['description'],
            'link' => url($feedConfig['route']),
            'image' => url($feedConfig['image']),
            'feed_url' => url("{$feedConfig['route']}/feed"),
            'owner_name' => $ownerName,
            'owner_email' => $ownerEmail,
            'author' => $author,
            'language' => $language,
            'category' => $category,
            'subcategory' => $subcategory,
            'explicit' => $explicit,
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
