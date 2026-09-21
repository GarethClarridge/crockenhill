<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a promoted detector writes its finding today.
 *
 * Four surfaces exist because the detectors were built at different times
 * against different carriers, and unifying them inside the pipeline would be a
 * rewrite of working code for no behavioural gain. The evaluation harness
 * instead reads each surface through its own adapter and normalises what it
 * finds, so the surface a detector happens to use never becomes a reason it
 * escapes evaluation.
 *
 * A signal name is only unique *within* a surface. `needs_review` on a video
 * quality verdict and a section review flag of the same spelling would be two
 * different claims, so the catalogue keys signals by surface and signal
 * together, never by signal alone.
 */
enum DetectorSurface: string
{
    use HasValues;

    /** `service_sections.metadata.review_flags`, ruled on by `SectionReviewFlagPolicy`. */
    case SectionReviewFlag = 'section_review_flag';

    /** `media_processing_logs.processing_metadata.service_transcript_suspect_blocks`. */
    case SuspectTranscriptBlock = 'suspect_transcript_block';

    /** A sermon's stored video quality status and its recorded reason. */
    case VideoQualityVerdict = 'video_quality_verdict';

    /** `SongPublicationReviewPolicy` objection kinds on a song section. */
    case SongPublicationReview = 'song_publication_review';

    public function label(): string
    {
        return match ($this) {
            self::SectionReviewFlag => 'Section review flag',
            self::SuspectTranscriptBlock => 'Suspect transcript block',
            self::VideoQualityVerdict => 'Video quality verdict',
            self::SongPublicationReview => 'Song publication review',
        };
    }
}
