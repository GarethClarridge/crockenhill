<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a case book entry's truth was established.
 *
 * §4.3a H4 scores detectors against *adjudicated* truth, and the plan is
 * explicit that several of its own labels are not that: the song-loop census
 * compared transcripts with catalogue lyrics rather than listening, and a fresh
 * decode is a second opinion, not ground truth (H10b). A case book that recorded
 * only "defective" would let those labels stand in for source evidence the
 * moment they were scored. So every case carries its basis, and only the bases
 * that genuinely settle the question count towards H4's rates. The rest remain
 * regression fixtures, which still fail a candidate that reintroduces them.
 */
enum DetectorCaseBasis: string
{
    use HasValues;

    /** A person heard or watched the source or the output. */
    case SourceReviewed = 'source_reviewed';

    /** The defect is a fact of stored rows or files: a span, a probe, a link. */
    case StoredRecord = 'stored_record';

    /** An operator ruled on the case. */
    case OperatorRuling = 'operator_ruling';

    /** A fresh decode of the source window disagreed with the stored text. */
    case SourceRedecode = 'source_redecode';

    /** Inferred from the stored transcript's own text. */
    case TranscriptReading = 'transcript_reading';

    /** Scored against another derived artefact, such as catalogue lyrics or the OoS. */
    case DerivedComparison = 'derived_comparison';

    /**
     * Whether this basis settles the truth well enough for H4's rates.
     *
     * A re-decode, a transcript reading and a derived comparison can each share
     * the error they are meant to judge, so they nominate a label rather than
     * establish one.
     */
    public function isAdjudicated(): bool
    {
        return match ($this) {
            self::SourceReviewed, self::StoredRecord, self::OperatorRuling => true,
            self::SourceRedecode, self::TranscriptReading, self::DerivedComparison => false,
        };
    }
}
