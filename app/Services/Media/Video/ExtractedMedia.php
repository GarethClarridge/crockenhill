<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

/**
 * One cut's outputs on the temp disk: the video, the public MP3 made from the same
 * treated sound when one was asked for, and the audio treatment report.
 */
final readonly class ExtractedMedia
{
    /**
     * @param  array<string, mixed>|null  $audio  null for an untreated cut
     */
    public function __construct(
        public string $videoPath,
        public ?string $audioPath = null,
        public ?array $audio = null,
    ) {}

    /** Parts left as recorded because their loudness could not be measured or corrected. */
    public function untreatedParts(): int
    {
        return count(array_filter($this->audio['parts'] ?? [], static fn (array $part): bool => ($part['untreated_reason'] ?? null) !== null));
    }
}
