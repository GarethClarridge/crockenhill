<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\SuspectTranscriptBlock;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;

/**
 * A song section whose transcript claims text the audio did not produce.
 *
 * {@see ServiceTranscriptRepetitionScreen} has run over every service transcript since P8-Q14,
 * and {@see \App\Actions\FlagSuspectTranscriptRepetition} holds a *sermon* on what it finds. No
 * song check ever read its blocks. The §4.1b census measured what that missed: 226 song sections
 * carrying blocks, 63 of them half loop or more, 25 unheld with 22 carrying a generated clip.
 *
 * **What condemns a section is not how much of it loops.** The line began as a share of the
 * section's seconds, and adjudicating the corpus on 2026-09-16 measured that the share barely
 * discriminates: below the half-loop line 59 of 85 sections repeat phrases their bound song does
 * not contain — a 69% defect rate against 80% above it. A threshold that hardly changes the
 * defect rate as it is crossed is drawing a number across a continuum, and it is wrong in both
 * directions: §1862 loops at 0.51 repeating "for the lord i will st" 96 times, a phrase nowhere
 * in its song, while §3750 loops well past the line singing its own chorus and was named one of
 * six genuine choruses by the 2026-09-14 census.
 *
 * So the phrase decides. A repetition block repeating words the bound song does not contain is
 * the decode looping; one repeating the song's own words is the congregation. Where the bound
 * song carries no catalogue lyrics there is nothing to compare — 11 of those 85 — and the share
 * still decides, so a thin catalogue cannot make a section read as clean.
 *
 * The risk is a demotion, not a content-defect hold: the singing may have been perfectly good,
 * and the defect is in the record of it. P8-Q14 keeps songs out of that hold for the same reason.
 */
final class SongLoopedTranscript
{
    public const RISK_KIND = 'song_looped_transcript';

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
     * One observation at most: this describes the section's transcript as a whole rather than
     * any one edge of it. An empty list means the screen ran and found nothing here.
     *
     * @return list<array{
     *     status: string,
     *     basis: string,
     *     looped_share: float|null,
     *     looped_seconds: float|null,
     *     blocks: int,
     *     phrases_absent_from_lyrics: int|null,
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
                basis: 'none',
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

        $lyrics = $this->lyricsFor($section);
        $absent = $lyrics === '' ? null : $this->phrasesAbsentFrom($clipped, $lyrics);

        [$risk, $basis, $detail] = match (true) {
            $absent === null => [
                $share >= $this->minimumShare(),
                'share',
                $share >= $this->minimumShare()
                    ? sprintf('The transcript is %.0f%% repetition blocks and the bound song has no catalogue lyrics to compare them against, so the share decides and it cannot evidence the performance.', $share * 100)
                    : sprintf('The transcript carries %.0f%% repetition blocks, under the share that withholds a clip, and the bound song has no catalogue lyrics to compare them against.', $share * 100),
            ],
            $absent > 0 => [
                true,
                'phrase_absent_from_lyrics',
                sprintf(
                    '%d repetition block(s) repeat words the bound song does not contain, so the transcript claims text the audio did not produce and cannot evidence which song was sung.',
                    $absent,
                ),
            ],
            default => [
                false,
                'phrases_are_the_song_repeating',
                sprintf('The transcript is %.0f%% repetition blocks, but every repeated phrase is in the bound song\'s own words, so this is the song repeating rather than the decode looping.', $share * 100),
            ],
        };

        return [$this->observation(
            status: 'screened',
            basis: $basis,
            detail: $detail,
            loopedShare: $share,
            loopedSeconds: round($seconds, 2),
            blocks: count($clipped),
            phrasesAbsentFromLyrics: $absent,
            crossesSection: $crosses,
            phrases: array_values(array_unique($phrases)),
            risk: $risk,
        )];
    }

    /**
     * What {@see self::observe()} reads. Banked evidence fingerprints this so a re-screened
     * transcript — or edited lyrics, which can change the verdict outright — makes a cleared
     * clip stale instead of leaving it cleared.
     *
     * @return array{blocks_recorded: bool, blocks_sha256: string|null, song_id: int|null, lyrics_sha256: string|null}
     */
    public function inputs(ServiceSection $section): array
    {
        $recorded = $section->processingLog->recordedTranscriptSuspectBlocks();
        $songId = $section->resolvedSongId();

        return [
            'blocks_recorded' => $recorded !== null,
            'blocks_sha256' => $recorded === null
                ? null
                : hash('sha256', (string) json_encode($recorded, JSON_THROW_ON_ERROR)),
            'song_id' => $songId,
            'lyrics_sha256' => $songId === null
                ? null
                : hash('sha256', (string) Song::query()->whereKey($songId)->value('lyrics_plain')),
        ];
    }

    /**
     * How many of these blocks repeat a phrase the lyrics do not contain.
     *
     * Null when no block carries a phrase at all — an implausible-density block records no
     * phrase — because nothing was compared and reading that as "all present" would clear a
     * section on an absence of evidence.
     *
     * @param  list<SuspectTranscriptBlock>  $blocks
     */
    private function phrasesAbsentFrom(array $blocks, string $lyrics): ?int
    {
        $judged = 0;
        $absent = 0;

        foreach ($blocks as $block) {
            $phrase = $this->normalise($block->phrase);

            if ($phrase === '') {
                continue;
            }

            $judged++;

            if (! str_contains($lyrics, $phrase)) {
                $absent++;
            }
        }

        return $judged === 0 ? null : $absent;
    }

    /** The bound song's lyrics, normalised, or an empty string when there are none to read. */
    private function lyricsFor(ServiceSection $section): string
    {
        $songId = $section->resolvedSongId();

        if ($songId === null) {
            return '';
        }

        return $this->normalise((string) Song::query()->whereKey($songId)->value('lyrics_plain'));
    }

    /**
     * Lower case, punctuation to spaces, runs of whitespace collapsed — so a phrase and the
     * lyrics are compared as words rather than as typography. The same normalisation the
     * 2026-09-16 adjudication used, which is what its measured rates describe.
     */
    private function normalise(?string $text): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower((string) $text)),
        ));
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
     * @param  list<string>  $phrases
     * @return array{
     *     status: string,
     *     basis: string,
     *     looped_share: float|null,
     *     looped_seconds: float|null,
     *     blocks: int,
     *     phrases_absent_from_lyrics: int|null,
     *     crosses_section: bool,
     *     phrases: list<string>,
     *     risk: bool,
     *     detail: string
     * }
     */
    private function observation(
        string $status,
        string $basis,
        string $detail,
        ?float $loopedShare = null,
        ?float $loopedSeconds = null,
        int $blocks = 0,
        ?int $phrasesAbsentFromLyrics = null,
        bool $crossesSection = false,
        array $phrases = [],
        bool $risk = false,
    ): array {
        return [
            'status' => $status,
            'basis' => $basis,
            'looped_share' => $loopedShare,
            'looped_seconds' => $loopedSeconds,
            'blocks' => $blocks,
            'phrases_absent_from_lyrics' => $phrasesAbsentFromLyrics,
            'crosses_section' => $crossesSection,
            'phrases' => $phrases,
            'risk' => $risk,
            'detail' => $detail,
        ];
    }

    /**
     * The share that decides when the phrases cannot be judged.
     *
     * Half the section, from the plan's original line. It is no longer the primary test — the
     * phrase is — but it remains the fallback for a song with no catalogue lyrics, where the
     * measured distribution offers no better answer: 63 sections sit at half or more and 85
     * between a fifth and a half.
     */
    private function minimumShare(): float
    {
        return (float) config(
            'media-processing.section_publishing.song_boundary.looped_transcript_minimum_share',
            0.5,
        );
    }
}
