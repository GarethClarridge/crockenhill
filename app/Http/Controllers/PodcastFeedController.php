<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonService;
use App\Services\PodcastFeedService;
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
        // Validate service type
        $validServices = [SermonService::MORNING->value, SermonService::EVENING->value];
        if (! in_array($service, $validServices, true)) {
            abort(404, 'Feed not found');
        }

        $sermons = $this->feedService->getSermonsForFeed($service);
        $metadata = $this->feedService->getFeedMetadata($service);

        $content = view("rss.{$service}Feed", [
            'sermons' => $sermons,
            'metadata' => $metadata,
        ])->render();

        return response($content, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
