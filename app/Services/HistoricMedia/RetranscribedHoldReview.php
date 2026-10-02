<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use JsonException;

/**
 * Whether a held talk's passage reads as restored in the text a re-transcription wrote (plan §4.0,
 * the report proposed 2026-09-25 and ruled before the first era batch).
 *
 * Tier A re-transcribes 170 runs, and 36 of the 64 held for transcript loss hold only records no
 * code check can re-test: they park at Tier C until a person looks. This applies the measures
 * run 1112's release rested on to each held passage of the new text: the pipeline's own
 * repetition screen (loops, sparse cadence, implausible density) finds nothing there, and no gap
 * between cues is longer than {@see self::MAX_GAP_SECONDS}. It proposes; the operator releases
 * (`service:release-content-hold`). Only code-found holds recheck themselves, by ruling, so
 * nothing here clears a hold.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class RetranscribedHoldReview
{
    /** The longest silence between cues 1112's restored sermon showed, and its release accepted. */
    public const MAX_GAP_SECONDS = 8.0;

    public const VERDICT_RESTORED = 'looks_restored';

    public const VERDICT_SUSPECT = 'still_suspect';

    public const VERDICT_UNCHANGED = 'text_unchanged';

    public const VERDICT_UNREADABLE = 'transcript_unreadable';

    private const REVIEWED_TYPES = [ServiceSectionType::Sermon, ServiceSectionType::ShortTalk];

    public function __construct(private readonly ServiceTranscriptRepetitionScreen $screen) {}

    /**
     * Every live hold on the run's sermons and talks, with its passage measured in the run's
     * current text.
     *
     * @return list<array{run: int, section: int, section_type: string, held_at: string|null, reason: string, found_by: string|null, transcript_loss: bool, text_changed_since_hold: bool|null, span: array{start: float, end: float}, measures: array{cues: int, words: int, words_per_minute: float|null, longest_gap_seconds: float, suspect_blocks: list<array{reason: string, start: float, end: float}>}|null, verdict: string, why: list<string>}>
     */
    public function review(MediaProcessingLog $run): array
    {
        $transcriptSha256 = $run->serviceTranscriptSha256();
        $transcript = $this->transcript($run);
        $blocks = $transcript === null ? [] : $this->screen->screen($transcript);
        $rows = [];

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $run->id)
            ->whereIn('section_type', array_map(static fn (ServiceSectionType $type): string => $type->value, self::REVIEWED_TYPES))
            ->whereJsonContains('metadata->review_flags', HoldSectionForContentReview::FLAG)
            ->orderBy('start_time')
            ->get();

        foreach ($sections as $section) {
            foreach (HoldSectionForContentReview::holdsIn($section->metadata?->toArray() ?? []) as $record) {
                if (! HoldSectionForContentReview::isLive($record)) {
                    continue;
                }

                $heldText = is_string($record['transcript_sha256'] ?? null) ? $record['transcript_sha256'] : null;
                $span = [
                    'start' => is_numeric($record['start_time'] ?? null) ? (float) $record['start_time'] : (float) $section->start_time,
                    'end' => is_numeric($record['end_time'] ?? null) ? (float) $record['end_time'] : (float) $section->end_time,
                ];
                $row = [
                    'run' => $run->id,
                    'section' => (int) $section->id,
                    'section_type' => $section->section_type->value,
                    'held_at' => is_string($record['held_at'] ?? null) ? $record['held_at'] : null,
                    'reason' => (string) ($record['reason'] ?? ''),
                    'found_by' => is_string($record['found_by'] ?? null) ? $record['found_by'] : null,
                    'transcript_loss' => TranscriptLossHolds::describesTranscriptLoss($record),
                    'text_changed_since_hold' => $heldText === null ? null : $heldText !== $transcriptSha256,
                    'span' => $span,
                    'measures' => null,
                    'verdict' => self::VERDICT_UNREADABLE,
                    'why' => ['the run\'s transcript cannot be read'],
                ];

                if ($transcript !== null) {
                    $measures = $this->measure($transcript, $blocks, $span['start'], $span['end']);
                    [$verdict, $why] = $this->verdict($row['text_changed_since_hold'], $measures);
                    $row = [...$row, 'measures' => $measures, 'verdict' => $verdict, 'why' => $why];
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<SuspectTranscriptBlock>  $blocks
     * @return array{cues: int, words: int, words_per_minute: float|null, longest_gap_seconds: float, suspect_blocks: list<array{reason: string, start: float, end: float}>}
     */
    private function measure(ChurchServiceTranscript $transcript, array $blocks, float $from, float $to): array
    {
        $cues = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => (float) $cue['end'] > $from && (float) $cue['start'] < $to,
        ));
        $words = 0;
        $cursor = $from;
        $longestGap = 0.0;

        foreach ($cues as $cue) {
            $words += count(preg_split('/\s+/u', trim((string) $cue['text']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $longestGap = max($longestGap, (float) $cue['start'] - $cursor);
            $cursor = max($cursor, (float) $cue['end']);
        }

        $longestGap = max($longestGap, $to - $cursor);
        $minutes = ($to - $from) / 60;

        return [
            'cues' => count($cues),
            'words' => $words,
            'words_per_minute' => $minutes > 0 ? round($words / $minutes, 1) : null,
            'longest_gap_seconds' => round($longestGap, 1),
            'suspect_blocks' => array_map(
                static fn (SuspectTranscriptBlock $block): array => ['reason' => $block->reason, 'start' => $block->start, 'end' => $block->end],
                $this->screen->within($blocks, [['start' => $from, 'end' => $to]]),
            ),
        ];
    }

    /**
     * @param  array{cues: int, words: int, words_per_minute: float|null, longest_gap_seconds: float, suspect_blocks: list<array{reason: string, start: float, end: float}>}  $measures
     * @return array{0: string, 1: list<string>}
     */
    private function verdict(?bool $textChanged, array $measures): array
    {
        if ($textChanged === false) {
            return [self::VERDICT_UNCHANGED, ['the hold is about this very text; re-transcription has not reached it']];
        }

        $why = [];

        if ($measures['suspect_blocks'] !== []) {
            $why[] = sprintf('the repetition screen still finds %d suspect block(s) here', count($measures['suspect_blocks']));
        }

        if ($measures['longest_gap_seconds'] > self::MAX_GAP_SECONDS) {
            $why[] = sprintf('a %.1f s gap between cues, over %.0f s', $measures['longest_gap_seconds'], self::MAX_GAP_SECONDS);
        }

        if ($measures['cues'] === 0) {
            $why[] = 'no text at all in the passage';
        }

        return $why === []
            ? [self::VERDICT_RESTORED, ['no suspect block and no gap over '.self::MAX_GAP_SECONDS.' s; listen to confirm before releasing']]
            : [self::VERDICT_SUSPECT, $why];
    }

    private function transcript(MediaProcessingLog $run): ?ChurchServiceTranscript
    {
        $contents = $run->storedServiceTranscriptContents();

        if ($contents === null) {
            return null;
        }

        try {
            $transcript = ChurchServiceTranscript::fromArray(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }

        return $transcript->isEmpty() ? null : $transcript;
    }
}
