<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;

/** Follow-on evidence for the linking announcements left at song starts (10-29 study). */
class DependentSongOpening
{
    /** The audit reason {@see CueSafeExtractionPlan::forSpans()} records a review under. */
    public const AUDIT_REASON = 'song_start_dependent';

    private const LINKING = ['and', 'but', 'or', 'because', 'then'];

    /**
     * As {@see Structure\SongSpeechEdges}: a song left this short is a fragment (1073 §1715, a 4 s
     * play-through split from its song), and dropping its announcement would leave a sub-second output.
     */
    private const MINIMUM_REMAINING_SECONDS = 30.0;

    private const ANNOUNCEMENT_WORDS = ['sing', 'sang', 'sung', 'singing', 'stand', 'standing', 'song', 'hymn', 'chorus', 'number', 'chosen', 'played'];

    /**
     * Cue times select text only; they never authorise a cut. Keep a sentence spanning several cues,
     * and recognise stops inside a cue. A sentence without a stop is not a droppable sentence.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return list<array{start: float, end: float, text: string, complete: bool}>
     */
    public function sentences(array $cues, float $cut): array
    {
        $sentences = [];
        $current = null;
        foreach ($cues as $cue) {
            if ($cue['start'] < $cut - 0.3 || $cue['start'] > $cut + 40.0) {
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+|(?<=[.!?]["”\x27])\s+/u', trim($cue['text'])) ?: [] as $piece) {
                $current ??= ['start' => $cue['start'], 'end' => $cue['end'], 'text' => '', 'complete' => false];
                $current['text'] = trim($current['text'].' '.$piece);
                $current['end'] = $cue['end'];
                if (preg_match('/[.!?]["”\x27]?$/u', $piece) === 1) {
                    $current['complete'] = true;
                    $sentences[] = $current;
                    $current = null;
                }
            }
        }
        if ($current !== null) {
            $sentences[] = $current;
        }

        return $sentences;
    }

    /** @param array{start: float, end: float, text: string, complete: bool} $sentence */
    public function linking(array $sentence): bool
    {
        return in_array(TranscriptCueEvidence::tokens($sentence['text'])[0] ?? '', self::LINKING, true);
    }

    /**
     * Decode through the first standalone sentence, to the same 0.8 s pause and 15 s cap as edge
     * evidence. Starting at the current cut keeps this evidence separate from its old timing window.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{start: float, end: float, window_rule: string}|null
     */
    public function window(MediaProcessingLog $log, array $cues, float $cut): ?array
    {
        $sentences = $this->sentences($cues, $cut);
        if ($sentences === [] || ! $this->linking($sentences[0])) {
            return null;
        }
        $target = $cut;
        foreach ($sentences as $sentence) {
            $target = max($target, $sentence['end']);
            if (! $this->linking($sentence)) {
                break;
            }
        }
        $windows = app(OutputEdgeDecodeWindow::class);
        $levels = $windows->levels($log);
        $bounds = $levels === null ? null : $windows->bounds($levels['samples'], $levels['threshold'], $target, $log->duration);
        $end = min($log->duration ?? INF, $cut + 15.0, max($target + 0.5, $bounds['end'] ?? $target + 1.0));
        if ($end <= $cut) {
            return null;
        }

        return ['start' => $cut, 'end' => $end, 'window_rule' => 'dependent-song-opening-pause-0.8-cap-15-v1'];
    }

    /**
     * The windows a song plan asked for ({@see CueSafeExtractionPlan::forSpans()} reviews each start
     * before any later override moves it), read from its audit so preparation decodes where
     * planning looks, whatever the plan went on to do with the cut.
     *
     * @param  list<array<string, mixed>>  $audit
     * @return list<array{start: float, end: float, window_rule: string}>
     */
    public function plannedWindows(array $audit): array
    {
        $windows = [];
        foreach ($audit as $entry) {
            $window = ($entry['reason'] ?? null) === self::AUDIT_REASON ? ($entry['dependent_opening']['window'] ?? null) : null;
            if (is_array($window) && isset($window['start'], $window['end'], $window['window_rule'])) {
                $windows[] = ['start' => (float) $window['start'], 'end' => (float) $window['end'], 'window_rule' => (string) $window['window_rule']];
            }
        }

        return $windows;
    }

    /**
     * Missing, looping or non-matching evidence is a soft miss. Only the complete transcript
     * sentence's exact decoded words can move a cut; cue ends never supply a timing.
     *
     * A sentence announces when it holds an announcement word, or names another song of the service
     * (1096: "Firstly, how can it be the one who died? And secondly, I know that my Redeemer lives.",
     * the second song of a pair; operator, 10-29). The song's own title does not count: it is often its
     * first sung line (1226's "And then he is exalted."). On the 28 linking starts left in place on
     * 10-29, another song's title named 1096 alone (`storage/scratch/title-naming-opening-20261029/`).
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  float|null  $sectionStart  The song section's own start, which names the song this opening belongs to
     * @return array{time: float, dropped: list<string>, stopped: string, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, window: array{start: float, end: float, window_rule: string}|null}
     */
    public function review(MediaProcessingLog $log, array $cues, float $cut, float $until, ?float $sectionStart = null): array
    {
        $window = $this->window($log, $cues, $cut);
        $result = ['time' => $cut, 'dropped' => [], 'stopped' => 'not_linking', 'word_before' => null, 'word_after' => null, 'window' => $window];
        if ($window === null) {
            return $result;
        }
        try {
            $words = app(OutputEdgeWordTimings::class)->read($log, $window)['words'];
        } catch (OutputEdgeTimingsMissing) {
            return [...$result, 'stopped' => 'missing_decode'];
        }
        $tokens = [];
        foreach ($words as $index => $word) {
            foreach (TranscriptCueEvidence::tokens($word['word']) as $token) {
                $tokens[] = ['token' => $token, 'index' => $index];
            }
        }
        $sentences = $this->sentences($cues, $cut);
        $normal = array_map(static fn (array $sentence): array => TranscriptCueEvidence::tokens($sentence['text']), $sentences);
        foreach ($sentences as $position => $sentence) {
            $result['stopped'] = 'not_linking';
            if (! $this->linking($sentence)) {
                break;
            }
            $result['stopped'] = 'incomplete_sentence';
            if (! $sentence['complete']) {
                break;
            }
            $expected = $normal[$position];
            $result['stopped'] = 'transcript_loop';
            if (($normal[$position - 1] ?? null) === $expected || ($normal[$position + 1] ?? null) === $expected) {
                break;
            }
            $result['stopped'] = 'not_announcement';
            if (array_intersect($expected, self::ANNOUNCEMENT_WORDS) === [] && ! $this->namesAnotherSong($log, $expected, $sectionStart)) {
                break;
            }
            $matches = [];
            for ($at = 0; $at + count($expected) <= count($tokens); $at++) {
                if (array_column(array_slice($tokens, $at, count($expected)), 'token') !== $expected) {
                    continue;
                }
                $first = $tokens[$at]['index'];
                $last = $tokens[$at + count($expected) - 1]['index'];
                if (($tokens[$at - 1]['index'] ?? null) === $first || ($tokens[$at + count($expected)]['index'] ?? null) === $last
                    || $words[$first]['start'] < $result['time'] - 0.3 || $words[$last]['end'] <= $result['time']
                    || $words[$last]['end'] > $window['end'] || $words[$last]['end'] <= $words[$last]['start']) {
                    continue;
                }
                $matches[] = [$first, $last];
            }
            $result['stopped'] = count($matches) > 1 ? 'ambiguous_decode' : 'decode_mismatch';
            if (count($matches) !== 1) {
                break;
            }
            [, $last] = $matches[0];
            $result['stopped'] = 'span_end';
            if ($words[$last]['end'] > $until - self::MINIMUM_REMAINING_SECONDS) {
                break;
            }
            $result['time'] = $words[$last]['end'];
            $result['word_before'] = $words[$last];
            $result['word_after'] = $words[$last + 1] ?? null;
            $result['dropped'][] = $sentence['text'];
            $result['stopped'] = 'transcript_end';
        }

        return $result;
    }

    /** @param  list<string>  $sentence */
    private function namesAnotherSong(MediaProcessingLog $log, array $sentence, ?float $sectionStart): bool
    {
        if ($sectionStart === null) {
            return false;
        }
        $sections = $log->serviceSections()->with('churchServiceItem')->where('section_type', ServiceSectionType::Song)->get();
        $own = $sections->first(static fn (ServiceSection $section): bool => abs((float) $section->start_time - $sectionStart) < 0.001)?->resolvedSongId();
        if ($own === null) {
            return false;
        }
        $songIds = array_unique(array_filter([
            ...$sections->map(static fn (ServiceSection $section): ?int => $section->resolvedSongId())->all(),
            ...($log->churchService?->items()->pluck('song_id')->all() ?? []),
        ]));
        $songs = Song::query()->whereKey($songIds)->get(['id', 'title', 'alternate_title']);
        $titles = static fn (Song $song): array => array_values(array_filter(array_map(
            static fn (?string $title): array => TranscriptCueEvidence::tokens((string) preg_replace(['/#\s*\d+/', '/\(.*?\)/'], '', (string) $title)),
            [$song->title, $song->alternate_title],
        ), static fn (array $tokens): bool => count($tokens) >= 2));
        $ownTitles = $titles($songs->firstWhere('id', $own) ?? new Song);

        foreach ($songs as $song) {
            if ($song->id === $own) {
                continue;
            }
            foreach ($titles($song) as $title) {
                if (in_array($title, $ownTitles, true)) {
                    continue;
                }
                for ($at = 0; $at + count($title) <= count($sentence); $at++) {
                    if (array_slice($sentence, $at, count($title)) === $title) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
