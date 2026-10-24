<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\AudioTimeline;
use UnexpectedValueException;

/**
 * Starts a song at the music that introduces it.
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
 */
final class SongMusicalIntroduction
{
    public const METADATA_KEY = 'song_musical_introduction';

    private const MINIMUM_WINDOWS = 2;

    private const DECIDED = [
        ServiceSectionPublicationStatus::Published,
        ServiceSectionPublicationStatus::Approved,
        ServiceSectionPublicationStatus::Rejected,
    ];

    /**
     * Sections whose music is their own: another song, and an `other` section such as music
     * before the service (1233), which D6 keeps apart from the songs. A talk's musical tail is
     * the next song's introduction, so the walk may cross into it (1154).
     */
    private const STOPS_AT = [
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

            $introduction = $this->introductionBefore($section, $sections, $timeline);

            if ($introduction !== null) {
                $this->apply($section, $introduction);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * @param  array<int, ServiceSection>  $sections
     * @return array{from: float, to: float, windows: int}|null
     */
    private function introductionBefore(ServiceSection $section, array $sections, AudioTimeline $timeline): ?array
    {
        $start = (float) $section->start_time;
        $floor = 0.0;

        foreach ($sections as $other) {
            if ($other->id !== $section->id && in_array($other->section_type, self::STOPS_AT, true) && (float) $other->start_time < $start) {
                $floor = max($floor, min((float) $other->end_time, $start));
            }
        }

        $windows = array_values(array_filter(
            $timeline->windows,
            static fn (array $window): bool => $window['start'] < $start - self::TOLERANCE_SECONDS && $window['start'] >= $floor - self::TOLERANCE_SECONDS,
        ));
        $count = 0;
        $from = null;

        for ($index = count($windows) - 1; $index >= 0; $index--) {
            $window = $windows[$index];

            if ($window['music'] < AudioTimeline::MUSIC_CUTOFF || $window['speech'] >= AudioTimeline::SPEECH_CUTOFF) {
                break;
            }

            $count++;
            $from = (float) $window['start'];
        }

        if ($count < self::MINIMUM_WINDOWS || $from === null || $from >= $start) {
            return null;
        }

        return ['from' => $start, 'to' => max($from, $floor), 'windows' => $count];
    }

    /**
     * @param  array{from: float, to: float, windows: int}  $introduction
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
