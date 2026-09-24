<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The check that found a content hold's defect.
 *
 * Recorded because the check that made a hold can usually re-test it: the
 * 2026-09-23 census of the 181 holds found none made by a person listening —
 * they came from loop screens, lyric comparisons, source re-decodes and media
 * measurements. Only the first two exist in code, so only they can re-run
 * themselves once the evidence they read has changed, and only for a defect the
 * code check can see at all. The rest are
 * decisions or judgements, and stay until someone re-decides them.
 */
enum ContentHoldCheck: string
{
    /**
     * The repetition screen, at its own floors, finds a loop the audio does not contain.
     * Only a loop that screen can see: a short loop below its floors is {@see self::SourceAudio}.
     */
    case LoopScreen = 'loop_screen';

    /** The sung words identify a different song from the one bound (the census lyric scorer). */
    case LyricComparison = 'lyric_comparison';

    /** A fresh decode of, or listening to, the source audio — including short loops and cadence hallucinations no screen in code can see. */
    case SourceAudio = 'source_audio';

    /** Frame, duration or file-length measurements of the media. */
    case MediaMeasurement = 'media_measurement';

    /** A judgement about where a section's content begins or ends. */
    case Boundary = 'boundary';

    /** A judgement about what the content is: a wrong title, passage or wording. */
    case Judgement = 'judgement';

    /** An open decision, such as which of two runs owns a performance. */
    case Decision = 'decision';

    /** Whether the check exists in code and can re-test the hold after a repair. */
    public function isRecheckable(): bool
    {
        return match ($this) {
            self::LoopScreen, self::LyricComparison => true,
            default => false,
        };
    }
}
