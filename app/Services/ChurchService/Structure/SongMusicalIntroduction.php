<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeDecodeWindow;
use App\Services\Media\Audio\AudioTimeline;
use UnexpectedValueException;

/**
 * Starts a song at the music that introduces it, or at the quiet singing that opens it.
 *
 * A song's musical introduction is always in the song when it is detected; a spoken one is
 * optional (register D6, amended 10-24). Clips opened on their first sung words after the band
 * had played in: 1117 left out 33 s, 1244 28 s, and some lost first-verse lines with it.
 *
 * Walking back from the start over the {@see AudioTimeline}'s 5 s windows, a window counts as
 * introduction when it reads as music and not speech. The walk stops at the first window that
 * does not, and at the end of any other song or `other` section: music inside the song before is
 * that song (1267's three hymns back to back), and music before the service is not a song's (1233). At least {@see self::MINIMUM_WINDOWS} windows move the start to
 * where the music begins; one window is the song's own opening overlapping its edge.
 *
 * Heard 10-24 against 36 clips that opened on their words after a wordless gap: it moves 6 of
 * the 7 that left out an introduction, to within 4 s of the start the operator gave, and 1 of
 * the 18 that left out nothing (1329). Loudness did not separate them; the classifier did. A
 * quiet introduction the classifier does not hear stays out (1235). Replayed over 425 runs it
 * moves 28 of 1,107 songs by 5-36 s. It moves the start and nothing else, so candidate
 * preparation re-cuts the clip on its changed media signature, and it leaves a section an
 * operator has published, approved or rejected alone.
 *
 * A song clip never starts partway through the song (register E25, 11-01). 1011 §1285 opened at
 * the band after 61 s of a solo voice far below it (−55 to −70 dB against −24 dB), music 0.10–0.33
 * and so no introduction above; its singing score, 0.049–0.070 with speech under 0.06, heard it.
 * So a window also counts when it reads as {@see self::isQuietSinging() quiet singing}, and that
 * crosses into an `other` section (the solo's first 18 s were a "Hymn introduction"), where music
 * does not: loud sound there is that item's. A start taken over quiet singing begins just before
 * the first sound after any dropout in its first window, as {@see CueSafeExtractionPlan}'s
 * dead-feed rule places one. Measured over the round's 1,392 song clips on 11-01, it moves only
 * 1011; the heard solos read singing 0.037–0.070, the readings heard beside them 0.001 or less.
 */
final class SongMusicalIntroduction
{
    public const METADATA_KEY = 'song_musical_introduction';

    private const MINIMUM_WINDOWS = 2;

    /** The quietest singing heard as sung, 11-01 (1240, 1345: 0.037); 1294's unheard 0.015–0.030 stays out. */
    public const float SINGING_CUTOFF = 0.035;

    /** Quiet singing with no voice over it: the heard solos read speech 0.013–0.052, readings 0.72–0.84. */
    private const float QUIET_SINGING_SPEECH_LIMIT = 0.1;

    /** What a start moved past a dropout keeps before the first sound, as the dead-feed rule does. */
    private const float DROPOUT_LEAD = 0.3;

    private const DECIDED = [
        ServiceSectionPublicationStatus::Published,
        ServiceSectionPublicationStatus::Approved,
        ServiceSectionPublicationStatus::Rejected,
    ];

    /**
     * Sections whose music is their own: another song, and an `other` section such as music
     * before the service (1233), which D6 keeps apart from the songs. A talk's musical tail is
     * the next song's introduction, so the walk may cross into it (1154), and so may an operator's
     * start set by ear ({@see CueSafeExtractionPlan}).
     */
    public const STOPS_AT = [
        ServiceSectionType::Song,
        ServiceSectionType::Other,
    ];

    private const TOLERANCE_SECONDS = 0.01;

    /**
     * @return int How many starts moved
     */
    public function extend(MediaProcessingLog $processingLog): int
    {
        $timeline = $this->timeline($processingLog);

        if ($timeline === null) {
            return 0;
        }

        $sections = $processingLog->serviceSections()->orderBy('start_time')->get()->all();
        $moved = 0;

        foreach ($sections as $section) {
            if ($section->section_type !== ServiceSectionType::Song || in_array($section->publication_status, self::DECIDED, true)) {
                continue;
            }

            $introduction = $this->introductionBefore($section, $sections, $timeline, $processingLog);

            if ($introduction !== null) {
                $this->apply($section, $introduction);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * A window of singing too quiet to read as music, with no voice over it.
     *
     * @param  array{start: float, end: float, music: float, speech: float, singing: float}  $window
     */
    public static function isQuietSinging(array $window): bool
    {
        return $window['singing'] >= self::SINGING_CUTOFF
            && $window['speech'] < self::QUIET_SINGING_SPEECH_LIMIT
            && $window['music'] < AudioTimeline::MUSIC_CUTOFF;
    }

    /**
     * @param  array<int, ServiceSection>  $sections
     * @return array{from: float, to: float, windows: int, quiet_singing_windows?: int}|null
     */
    private function introductionBefore(ServiceSection $section, array $sections, AudioTimeline $timeline, MediaProcessingLog $processingLog): ?array
    {
        $start = (float) $section->start_time;
        $songFloor = 0.0;
        $otherFloor = 0.0;

        foreach ($sections as $other) {
            if ($other->id === $section->id || (float) $other->start_time >= $start) {
                continue;
            }

            $end = min((float) $other->end_time, $start);

            match ($other->section_type) {
                ServiceSectionType::Song => $songFloor = max($songFloor, $end),
                ServiceSectionType::Other => $otherFloor = max($otherFloor, $end),
                default => null,
            };
        }

        $windows = array_values(array_filter(
            $timeline->windows,
            static fn (array $window): bool => $window['start'] < $start - self::TOLERANCE_SECONDS && $window['start'] >= $songFloor - self::TOLERANCE_SECONDS,
        ));
        $count = 0;
        $quiet = 0;
        $first = null;

        for ($index = count($windows) - 1; $index >= 0; $index--) {
            $window = $windows[$index];
            $quietSinging = self::isQuietSinging($window);
            $music = $window['music'] >= AudioTimeline::MUSIC_CUTOFF && $window['speech'] < AudioTimeline::SPEECH_CUTOFF;
            $insideOther = $window['start'] < $otherFloor - self::TOLERANCE_SECONDS;

            if (! $quietSinging && ($insideOther || ! $music)) {
                break;
            }

            $count++;
            $quiet += $quietSinging ? 1 : 0;
            $first = $window;
        }

        if ($count < self::MINIMUM_WINDOWS || $first === null || $first['start'] >= $start) {
            return null;
        }

        $to = max($first['start'], $songFloor, $first['start'] < $otherFloor - self::TOLERANCE_SECONDS ? 0.0 : $otherFloor);

        if (self::isQuietSinging($first)) {
            $to = $this->afterDropout($processingLog, $to, $first['end']) ?? $to;
        }

        $introduction = ['from' => $start, 'to' => $to, 'windows' => $count];

        if ($quiet > 0) {
            $introduction['quiet_singing_windows'] = $quiet;
        }

        return $introduction;
    }

    /**
     * Just before the first sound in a stretch that opens in a dropout, or null when it does not
     * open in one or the run has no level log.
     */
    private function afterDropout(MediaProcessingLog $processingLog, float $from, float $until): ?float
    {
        $levels = app(OutputEdgeDecodeWindow::class)->levels($processingLog);
        $opensInDropout = false;

        foreach ($levels['samples'] ?? [] as $sample) {
            if ($sample['time'] < $from || $sample['time'] > $until) {
                continue;
            }

            if ($sample['rms'] <= DeadFeedInsideSection::DROPOUT_LEVEL_DB) {
                $opensInDropout = true;

                continue;
            }

            return $opensInDropout ? max($from, $sample['time'] - self::DROPOUT_LEAD) : null;
        }

        return null;
    }

    /**
     * @param  array{from: float, to: float, windows: int, quiet_singing_windows?: int}  $introduction
     */
    private function apply(ServiceSection $section, array $introduction): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $recorded = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [];
        $recorded[] = [...$introduction, 'extended_at' => now()->toIso8601String()];

        $metadata[self::METADATA_KEY] = $recorded;
        $section->start_time = $introduction['to'];
        $section->duration = max(0.0, (float) $section->end_time - $introduction['to']);
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }

    private function timeline(MediaProcessingLog $processingLog): ?AudioTimeline
    {
        $contents = $processingLog->storedAudioTimelineContents();

        if ($contents === null) {
            return null;
        }

        try {
            return AudioTimeline::fromJson($contents);
        } catch (UnexpectedValueException) {
            return null;
        }
    }
}
