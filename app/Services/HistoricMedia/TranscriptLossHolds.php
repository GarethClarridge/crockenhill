<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ContentHoldCheck;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

/**
 * The live content holds that say a run's stored transcript is wrong where its audio is not:
 * the grounds for re-transcribing it (plan §4.0, Tier A).
 *
 * The definition is the one the 2026-09-24 census counted with (64 runs,
 * `storage/scratch/tier-b-transcript-loss-20260924.php`): a live hold found by the loop screen,
 * or one whose reason is a short loop, fragmentation, sparse-cadence loss or BC-08 drift. Holds
 * on the source itself (silence, a cut-off recording), on boundaries or on song identity are
 * not transcript loss, because re-transcribing cannot change them.
 *
 * A hold counts only while it describes the transcript the run holds now: its
 * `transcript_sha256` matches, or it predates fingerprinting. A run already re-transcribed has
 * moved past the text the hold was about.
 */
final class TranscriptLossHolds
{
    /** @var list<string> */
    private const REASONS = [
        'short_transcript_loop_source_mismatch',
        'held_sample_short_transcript_loop',
        'reserved_fragmentation_source_mismatch',
    ];

    /**
     * Operator-written reasons, matched by their opening words as the census matched them.
     *
     * @var list<string>
     */
    private const REASON_PREFIXES = [
        'Saved sermon text repeats a loop',
        'Saved sermon text lost a passage to a sparse',
        'Blind review BC-08 confirms context-carried',
    ];

    /**
     * @return list<array{section: int, reason: string}>
     */
    public function on(MediaProcessingLog $run): array
    {
        $transcriptSha256 = $run->serviceTranscriptSha256();
        $holds = [];

        ServiceSection::query()
            ->where('media_processing_log_id', $run->id)
            ->whereJsonContains('metadata->review_flags', HoldSectionForContentReview::FLAG)
            ->orderBy('id')
            ->each(function (ServiceSection $section) use ($transcriptSha256, &$holds): void {
                foreach (HoldSectionForContentReview::holdsIn($section->metadata?->toArray() ?? []) as $record) {
                    $heldText = $record['transcript_sha256'] ?? null;

                    if (! HoldSectionForContentReview::isLive($record)
                        || ! self::describesTranscriptLoss($record)
                        || (is_string($heldText) && $heldText !== $transcriptSha256)) {
                        continue;
                    }

                    $holds[] = ['section' => (int) $section->id, 'reason' => (string) ($record['reason'] ?? '')];
                }
            });

        return $holds;
    }

    /**
     * Whether a hold record says the text lost content the audio has, by the census definition.
     *
     * @param  array<string, mixed>  $record
     */
    public static function describesTranscriptLoss(array $record): bool
    {
        if (($record['found_by'] ?? null) === ContentHoldCheck::LoopScreen->value) {
            return true;
        }

        $reason = (string) ($record['reason'] ?? '');

        if (in_array($reason, self::REASONS, true)) {
            return true;
        }

        foreach (self::REASON_PREFIXES as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
