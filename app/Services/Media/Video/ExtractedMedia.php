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

    /**
     * Each loudness or peak target a treated part missed in the video or the MP3.
     *
     * @return list<string>
     */
    public function loudnessMisses(): array
    {
        return array_values(array_filter($this->audio['loudness_misses'] ?? [], 'is_string'));
    }

    /**
     * A4: video true peak is a reported target; integrated loudness and the public
     * MP3 ceiling still require review. Unknown report entries remain actionable.
     *
     * @return list<string>
     */
    public function loudnessReviewMisses(): array
    {
        return array_values(array_filter(
            $this->loudnessMisses(),
            static fn (string $miss): bool => preg_match('/^part \d+ video true peak /', $miss) !== 1,
        ));
    }

    /**
     * Each part whose speech stands less than `$marginDb` above the quietest stretch between its words.
     *
     * @return list<string>
     */
    public function speechNearNoise(float $marginDb): array
    {
        $parts = [];

        foreach ($this->audio['parts'] ?? [] as $index => $part) {
            $pause = is_array($part) ? ($part['pause_relative'] ?? null) : null;

            if (is_numeric($pause) && -(float) $pause < $marginDb) {
                $parts[] = sprintf('part %d speech %.1f dB above its pauses', $index + 1, -(float) $pause);
            }
        }

        return $parts;
    }
}
