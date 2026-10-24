<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\SongMusicalIntroduction;
use App\Services\Media\Audio\AudioTimeline;

/**
 * A song clip that opens on its first words after a long time with no words and no music heard.
 *
 * A musical introduction is always in the song when it is detected (register D6, amended 10-24),
 * and {@see SongMusicalIntroduction} moves the start over one the classifier hears. One it does
 * not hear stays out: 1235 §2918 opened on its words after 21 s with no words, and the operator
 * heard "First notes at 15:19". The same shape is a clip carrying silence the transcript invented
 * words over (947, 962, 1367). Neither can be told from the evidence, so the clip goes to a
 * reviewer, who sets the start by ear or releases it.
 *
 * It is raised when the clip's first transcript line begins within
 * {@see self::OPENING_ON_WORDS_SECONDS} of its start, the last line before it ended at least
 * {@see self::MINIMUM_WORDLESS_SECONDS} earlier, the classifier heard music in fewer than two of
 * the windows between, and no other song ended in that time.
 */
final class SongQuietIntroduction
{
    public const RISK_KIND = 'song_possible_quiet_introduction';

    private const OPENING_ON_WORDS_SECONDS = 3.0;

    private const MINIMUM_WORDLESS_SECONDS = 15.0;

    private const MINIMUM_MUSIC_WINDOWS = 2;

    /** The filler whisper writes over sound it cannot hear is not words (§4.3). */
    private const FILLER = ['thank you.', 'thank you', 'you', '.', '. . .'];

    /**
     * @return array{risk: bool, wordless_from: float, start: float, music_windows: int, detail: string}|null
     */
    public function observe(ServiceSection $section, ChurchServiceTranscript $transcript, ?AudioTimeline $timeline): ?array
    {
        if ($timeline === null) {
            return null;
        }

        $start = (float) $section->start_time;
        $lines = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => ! in_array(mb_strtolower(trim($cue['text'])), self::FILLER, true),
        ));
        $before = array_values(array_filter($lines, static fn (array $cue): bool => $cue['start'] < $start - 0.5));
        $after = array_values(array_filter($lines, static fn (array $cue): bool => $cue['start'] >= $start - 0.5));

        if ($after === [] || $after[0]['start'] - $start > self::OPENING_ON_WORDS_SECONDS) {
            return null;
        }

        $wordlessFrom = $before === [] ? 0.0 : min((float) $before[count($before) - 1]['end'], $start);

        if ($start - $wordlessFrom < self::MINIMUM_WORDLESS_SECONDS || $this->songEndsBetween($section, $wordlessFrom, $start)) {
            return null;
        }

        $music = count(array_filter(
            $timeline->windows,
            static fn (array $window): bool => $window['end'] > $wordlessFrom && $window['start'] < $start
                && $window['music'] >= AudioTimeline::MUSIC_CUTOFF && $window['speech'] < AudioTimeline::SPEECH_CUTOFF,
        ));

        if ($music >= self::MINIMUM_MUSIC_WINDOWS) {
            return null;
        }

        return [
            'risk' => true,
            'wordless_from' => $wordlessFrom,
            'start' => $start,
            'music_windows' => $music,
            'detail' => sprintf(
                'The clip opens on its first words after %.0f s with no words (from %.1fs), and the classifier heard no music there: a quiet introduction may be left out, or the time is silence.',
                $start - $wordlessFrom,
                $wordlessFrom,
            ),
        ];
    }

    private function songEndsBetween(ServiceSection $section, float $from, float $to): bool
    {
        return $section->processingLog->serviceSections()
            ->whereKeyNot($section->id)
            ->where('section_type', ServiceSectionType::Song->value)
            ->where('end_time', '>', $from)
            ->where('end_time', '<=', $to + 1.0)
            ->exists();
    }
}
