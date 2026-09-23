<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\Media\Audio\SustainedSound;

/**
 * A transcript loop that manufactures sung text over speech.
 *
 * The §4.1b song-loop census found song sections swallowing the neighbouring prayer or talk
 * because the decoder looped a sung line over it: 1268 §4731 repeats "we honour and adore you"
 * 63 times over the prayer that §4732 then continues, and 967 §1082 and 1348 §4390 do the same.
 * {@see SongLoopedTranscript} judges the *text*; a phrase the song really contains passes it. The
 * audio says what the text cannot: under a real sung loop the congregation is still singing,
 * and under these the sound has the pauses of speech.
 *
 * Measured 2026-09-23 over 513 loop blocks inside song sections
 * (`speechloop-20260923-measure.json`): a repeated-phrase block of
 * {@see self::MINIMUM_SECONDS} or more under {@see self::MAXIMUM_SUSTAINED_SHARE} sustained, on a
 * run whose other songs read as sung, marks 12 sections — §1082, §4390, §4449 and §4731 among
 * them, all 12 already held — and the count does not move between 0.1 and 0.2. §4816 (0.30) and
 * §4525 (0.47) sit above it and are held by the loop rule already.
 */
final class SongSpeechUnderLoop
{
    public const RISK_KIND = 'song_speech_under_loop';

    private const MINIMUM_SECONDS = 30.0;

    private const MAXIMUM_SUSTAINED_SHARE = 0.2;

    /** The {@see \App\Services\ChurchService\Structure\SongSpeechEdges} guard: below this the run's singing reads as speech. */
    private const MINIMUM_OTHER_SONGS_SHARE = 0.6;

    /**
     * Null when the sound cannot speak for this run.
     *
     * @param  list<SuspectTranscriptBlock>  $blocks  The run's blocks, clipped to the section
     * @return array{spans: list<array{start: float, end: float, sustained_share: float}>, risk: bool, detail: string}|null
     */
    public function observe(ServiceSection $section, array $blocks, ?SustainedSound $sound): ?array
    {
        if (! $sound instanceof SustainedSound || ! $this->otherSongsReadAsSung($section, $sound)) {
            return null;
        }

        $spans = [];

        foreach ($blocks as $block) {
            if ($block->reason !== SuspectTranscriptBlock::REASON_REPEATED_PHRASE || $block->end - $block->start < self::MINIMUM_SECONDS) {
                continue;
            }

            $share = $sound->share($block->start, $block->end);

            if ($share < self::MAXIMUM_SUSTAINED_SHARE) {
                $spans[] = ['start' => $block->start, 'end' => $block->end, 'sustained_share' => round($share, 2)];
            }
        }

        return [
            'spans' => $spans,
            'risk' => $spans !== [],
            'detail' => $spans === []
                ? 'No repeated-phrase loop in this section sits over speech.'
                : sprintf(
                    '%d looped span(s) (%s) repeat a sung line over sound with the pauses of speech, so the text was not sung there.',
                    count($spans),
                    implode(', ', array_map(static fn (array $span): string => sprintf('%.0f–%.0f s', $span['start'], $span['end']), $spans)),
                ),
        ];
    }

    private function otherSongsReadAsSung(ServiceSection $section, SustainedSound $sound): bool
    {
        $shares = $section->processingLog->serviceSections()
            ->where('id', '!=', $section->id)
            ->where('section_type', ServiceSectionType::Song->value)
            ->get()
            ->map(static fn (ServiceSection $other): float => $sound->share((float) $other->start_time, (float) $other->end_time))
            ->sort()
            ->values()
            ->all();

        if ($shares === []) {
            return false;
        }

        $middle = intdiv(count($shares), 2);
        $median = count($shares) % 2 === 1 ? $shares[$middle] : ($shares[$middle - 1] + $shares[$middle]) / 2;

        return $median >= self::MINIMUM_OTHER_SONGS_SHARE;
    }
}
