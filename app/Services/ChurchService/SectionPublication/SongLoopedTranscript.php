<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\SuspectTranscriptBlock;
use App\Models\ServiceSection;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;

/**
 * A song section whose transcript is largely a decode loop.
 *
 * {@see ServiceTranscriptRepetitionScreen} has run over every service transcript since P8-Q14,
 * and {@see \App\Actions\FlagSuspectTranscriptRepetition} holds a *sermon* on what it finds. No
 * song check ever read its blocks. The §4.1b census (2026-09-14) measured what that missed:
 * 229 song sections carrying blocks, 65 of them half loop or more. Re-anchored to current
 * membership on 2026-09-16 it is 226 and 63, of which 25 were unheld, 22 with a generated clip,
 * and every one of the 25 bound to its song as `confirmed`.
 *
 * A looping transcript is not evidence of a performance. It claims text the audio did not
 * produce — §1862's "for the lord i will stand" 96 times, §2309's "we're going to be a so" 89
 * times — so a section resting on it cannot show which song was sung, nor where it began and
 * ended. That is why this raises a review risk rather than a content hold: the material may be
 * perfectly good, and the defect is in the record of it. P8-Q14 deliberately keeps songs out of
 * the content-defect hold for the same reason.
 *
 * Half the section's seconds is the line, taken from the plan. Adjudicating the 25 unheld
 * sections against their bound songs' lyrics on 2026-09-16 found 20 real loops, 1 genuine
 * repeating chorus, 2 mixed and 2 whose song carries no lyrics to judge against, so the line
 * does not stand alone: whether the looped phrase occurs in the song's own words is what
 * separates a decode loop from a refrain, and that remains a reviewer's judgement here.
 *
 * A block crossing the section's edge is measured and recorded but raises nothing on its own.
 * Nothing has measured that class yet, and a rule built ahead of its measurement is the mistake
 * this programme keeps finding.
 */
final class SongLoopedTranscript
{
    public const RISK_KIND = 'song_looped_transcript';

    /**
     * Blocks the screen recorded for this section's run, clipped to the section.
     *
     * One observation at most: this describes the section's transcript as a whole rather than
     * any one edge of it. An empty list means the screen ran and found nothing here.
     *
     * @return list<array{
     *     status: string,
     *     looped_share: float|null,
     *     looped_seconds: float|null,
     *     blocks: int,
     *     crosses_section: bool,
     *     phrases: list<string>,
     *     risk: bool,
     *     detail: string
     * }>
     */
    public function observe(ServiceSection $section): array
    {
        $recorded = $section->processingLog->recordedTranscriptSuspectBlocks();

        if ($recorded === null) {
            return [$this->observation(
                status: 'blocks_not_recorded',
                detail: 'This run carries no record of a repetition screen, so whether its transcript loops is unknown rather than settled.',
            )];
        }

        if ($recorded === []) {
            return [];
        }

        // No zero-duration guard: `service_sections_timing_invariants_check` enforces
        // `end_time > start_time` in the database, so the share below cannot divide by zero.
        $start = (float) $section->start_time;
        $end = (float) $section->end_time;

        ['blocks' => $clipped, 'crosses' => $crosses, 'phrases' => $phrases] = $this->clip($section);

        if ($clipped === []) {
            return [];
        }

        $seconds = SuspectTranscriptBlock::coveredSeconds($clipped);
        $share = round(min(1.0, $seconds / ($end - $start)), 3);
        $risk = $share >= $this->minimumShare();

        return [$this->observation(
            status: 'screened',
            detail: $risk
                ? sprintf(
                    'The transcript is %.0f%% repetition blocks, so it cannot evidence which song was sung or where it began and ended.',
                    $share * 100,
                )
                : sprintf('The transcript carries %.0f%% repetition blocks, under the share that withholds a clip.', $share * 100),
            loopedShare: $share,
            loopedSeconds: round($seconds, 2),
            blocks: count($clipped),
            crossesSection: $crosses,
            phrases: array_values(array_unique($phrases)),
            risk: $risk,
        )];
    }

    /**
     * The recorded blocks overlapping this section, clipped to it.
     *
     * Exposed because a looping transcript is not only a reason to withhold the whole clip: the
     * silence between two looped cues is not evidence of where the singing began or ended
     * either, so {@see SongPublicationBoundaryEvidenceService} discounts a gap that falls inside
     * one, the way it already discounts a gap inside an unobservable window.
     *
     * @return list<SuspectTranscriptBlock>
     */
    public function blocksFor(ServiceSection $section): array
    {
        return $this->clip($section)['blocks'];
    }

    /**
     * @return array{blocks: list<SuspectTranscriptBlock>, crosses: bool, phrases: list<string>}
     */
    private function clip(ServiceSection $section): array
    {
        $start = (float) $section->start_time;
        $end = (float) $section->end_time;

        $blocks = [];
        $phrases = [];
        $crosses = false;

        foreach ($section->processingLog->recordedTranscriptSuspectBlocks() ?? [] as $row) {
            $block = SuspectTranscriptBlock::fromArray($row);

            if (! $block->overlaps($start, $end)) {
                continue;
            }

            if ($block->start < $start || $block->end > $end) {
                $crosses = true;
            }

            if (is_string($block->phrase) && $block->phrase !== '') {
                $phrases[] = $block->phrase;
            }

            $blocks[] = new SuspectTranscriptBlock(
                start: max($start, $block->start),
                end: min($end, $block->end),
                reason: $block->reason,
                words: $block->words,
                wordsPerMinute: $block->wordsPerMinute,
                phrase: $block->phrase,
                repeats: $block->repeats,
            );
        }

        return ['blocks' => $blocks, 'crosses' => $crosses, 'phrases' => $phrases];
    }

    /**
     * What {@see self::observe()} reads. Banked evidence fingerprints this so a re-screened
     * transcript makes a cleared clip stale instead of leaving it cleared.
     *
     * @return array{blocks_recorded: bool, blocks_sha256: string|null}
     */
    public function inputs(ServiceSection $section): array
    {
        $recorded = $section->processingLog->recordedTranscriptSuspectBlocks();

        return [
            'blocks_recorded' => $recorded !== null,
            'blocks_sha256' => $recorded === null
                ? null
                : hash('sha256', (string) json_encode($recorded, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  list<string>  $phrases
     * @return array{
     *     status: string,
     *     looped_share: float|null,
     *     looped_seconds: float|null,
     *     blocks: int,
     *     crosses_section: bool,
     *     phrases: list<string>,
     *     risk: bool,
     *     detail: string
     * }
     */
    private function observation(
        string $status,
        string $detail,
        ?float $loopedShare = null,
        ?float $loopedSeconds = null,
        int $blocks = 0,
        bool $crossesSection = false,
        array $phrases = [],
        bool $risk = false,
    ): array {
        return [
            'status' => $status,
            'looped_share' => $loopedShare,
            'looped_seconds' => $loopedSeconds,
            'blocks' => $blocks,
            'crosses_section' => $crossesSection,
            'phrases' => $phrases,
            'risk' => $risk,
            'detail' => $detail,
        ];
    }

    /**
     * Half the section, from the plan's own line.
     *
     * The measured distribution is not a cliff — 63 sections sit at half or more and 85 between
     * a fifth and a half — so this is a policy choice about what may publish itself unreviewed,
     * not a natural boundary in the data. The 85 below the line are recorded and unaddressed.
     */
    private function minimumShare(): float
    {
        return (float) config(
            'media-processing.section_publishing.song_boundary.looped_transcript_minimum_share',
            0.5,
        );
    }
}
