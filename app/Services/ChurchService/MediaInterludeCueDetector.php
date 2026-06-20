<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

/**
 * Detects lead-in cue phrases in a section transcript that signal an upcoming
 * media/video interlude (e.g. "we're going to watch a video in just a moment").
 *
 * This is a deliberately cheap keyword pass: it corroborates the primary
 * OoS-anchored media signal in {@see StructuralSectionAligner} and never relies on
 * visual analysis, because the projector video may not be present in the
 * livestream feed.
 */
class MediaInterludeCueDetector
{
    /**
     * @var list<string>
     */
    private const CUE_PHRASES = [
        'watch a video',
        'watch this video',
        'watch the video',
        'watch a short',
        'play the video',
        'play a video',
        'play this video',
        'show the video',
        'show you a video',
        'video clip',
        'short clip',
        'short film',
        'roll the video',
        "we're going to see",
        'we are going to see',
        "we're going to watch",
        'we are going to watch',
        'going to watch a',
        'going to play',
        'mission update',
        'take a look at this',
        'have a look at this video',
    ];

    public function hasCue(?string $transcript): bool
    {
        if (! is_string($transcript) || trim($transcript) === '') {
            return false;
        }

        $lower = strtolower($transcript);

        foreach (self::CUE_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
