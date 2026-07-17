<?php

declare(strict_types=1);

namespace App\Data;

final readonly class PodcastFeedItemReadModel
{
    public function __construct(
        public string $canonicalUrl,
        public int $enclosureLength,
        public string $enclosureUrl,
        public ?string $episodeImageUrl,
        public string $itunesDuration,
        public string $podcastSummary,
        public ?string $preacherName,
        public string $publishedAt,
        public int $sermonId,
        public string $title,
        public ?string $transcriptUrl,
    ) {}
}
