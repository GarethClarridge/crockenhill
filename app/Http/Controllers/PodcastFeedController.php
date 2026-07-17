<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonService;
use App\Services\Public\PodcastFeedService;
use Illuminate\Http\Response;

class PodcastFeedController extends Controller
{
    public function __construct(
        private PodcastFeedService $feedService
    ) {}

    /**
     * Display the podcast RSS feed for a given service type
     */
    public function show(string $service): Response
    {
        $serviceEnum = SermonService::tryFrom($service);
        if ($serviceEnum === null || ! in_array($serviceEnum, [SermonService::Morning, SermonService::Evening], true)) {
            abort(404, 'Feed not found');
        }

        $feedItems = $this->feedService->getSermonsForFeed($serviceEnum);
        $metadata = $this->feedService->getFeedMetadata($service);

        $content = view('rss.feed', [
            'feedItems' => $feedItems,
            'metadata' => $metadata,
        ])->render();

        // HTTP freshness must not exceed the origin cache's fresh TTL, or
        // clients keep serving old XML long after the origin has moved on
        // (including zero-length enclosures the origin has already healed).
        $maxAge = (int) config('podcast.cache.ttl', 300);

        return response($content, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => "public, max-age={$maxAge}",
        ]);
    }
}
