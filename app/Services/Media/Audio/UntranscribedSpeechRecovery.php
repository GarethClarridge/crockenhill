<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Jobs\ClassifyServiceAudio;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\ChurchService\TranscriptCueEvidence;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Speech the classifier heard but the transcript has no line for, decoded again before structure
 * detection reads the transcript.
 *
 * Detection decides what belongs to which item from the words, so speech with no words is a gap
 * it draws around: canary 11's three F11 stretches (949, 1025, 1311) were the openings of the
 * items after them, or partly the end of the one before, and listening could not settle which
 * by rule (operator, 2026-10-06: "Shouldn't we just process it using the normal logic?").
 * Re-decoding the corpus's 62 such stretches on the local whisper returned words for 57, so the
 * stretch is decoded and spliced in as ordinary cues, and detection assigns it like any speech.
 *
 * Only where no cue that is evidence of speech covers the stretch ({@see TranscriptCueEvidence}):
 * canary 12 found 1025's 615–631 under a 30 s "Thank you." that had counted as coverage. A long
 * cue's text may still be real words badly timed, so a decode beneath it replaces it only when
 * the decode says its words, or reached every stretch of speech beneath it; otherwise it stays,
 * and stays no evidence. The run records the cues each attempt found suspect and replaced.
 * A decode that hears nothing, or loops, changes nothing, so
 * {@see UntranscribedSpeechBeforeSection} still marks the stretch afterwards.
 */
class UntranscribedSpeechRecovery
{
    /** The kind the recovered transcript is stored under, beside the run's other transcript versions. */
    public const ARTIFACT_KIND = 'normalized-speech-recovered';

    /** Where each attempt is recorded on the run, recovered or not. */
    public const METADATA_KEY = 'untranscribed_speech_recovery';

    /** Classifier speech score a window must reach, as {@see UntranscribedSpeechBeforeSection} measures it. */
    private const MINIMUM_SPEECH_SCORE = 0.6;

    /** Shorter gaps between cues are pauses between lines, not lost speech (F11's measured floor). */
    private const MINIMUM_STRETCH_SECONDS = 10.0;

    /** Audio decoded beyond each end of the stretch, so its first and last words are whole. */
    private const DECODE_MARGIN_SECONDS = 1.0;

    /** A pause between recovered words this long starts a new cue. */
    private const CUE_BREAK_SECONDS = 1.0;

    /** A recovered cue is closed after this long, as a transcript line would be. */
    private const LONGEST_CUE_SECONDS = 10.0;

    /** Fewer distinct words than this share of four or more is a decoding loop, not speech. */
    private const MINIMUM_DISTINCT_SHARE = 0.5;

    public function __construct(
        private readonly ServiceTranscriptionInterface $transcription,
        private readonly ServiceAudioWindowExtractor $extractor,
        private readonly ServiceArtifactStorage $artifacts,
        private readonly StorageAdapterHelper $storage,
    ) {}

    /**
     * @return array{stretches: int, recovered: int}
     */
    public function recover(MediaProcessingLog $log): array
    {
        $transcriptPath = $log->serviceTranscriptPath();
        $timelinePath = $log->audio_timeline_path;

        if ($transcriptPath === null || ! is_string($timelinePath) || $timelinePath === '') {
            return ['stretches' => 0, 'recovered' => 0];
        }

        // Detection refuses a run whose transcript or timeline it cannot read, with its own reason.
        try {
            $transcript = ChurchServiceTranscript::fromArray(json_decode((string) Storage::disk(ServiceArtifactDisk::for($transcriptPath))->get($transcriptPath), true));
            $timeline = AudioTimeline::fromJson((string) Storage::disk(ServiceArtifactDisk::for($timelinePath))->get($timelinePath));
        } catch (\Throwable) {
            return ['stretches' => 0, 'recovered' => 0];
        }
        $stretches = $this->stretches($transcript, $timeline);
        $suspect = array_values(array_filter($transcript->cues, static fn (array $cue): bool => ! TranscriptCueEvidence::isEvidence($cue, $timeline)));

        if ($stretches === []) {
            return ['stretches' => 0, 'recovered' => 0];
        }

        $audio = ClassifyServiceAudio::recordedAudio($log);

        if ($audio === null) {
            return ['stretches' => count($stretches), 'recovered' => 0];
        }

        $isDownloaded = $this->storage->isS3CompatibleDisk(Storage::disk($audio['disk']));
        $local = $this->storage->downloadToTemp($audio['path'], $audio['disk'], 'local', 'temp/untranscribed-speech');
        $recoveredCues = [];
        $recoveredWords = [];
        $decoded = [];
        $attempts = [];

        try {
            foreach ($stretches as [$start, $end]) {
                $words = $this->decode($log, $local, $start, $end, $transcript->duration);
                $cues = $this->cues($words);
                $recoveredCues = [...$recoveredCues, ...$cues];

                if ($cues !== []) {
                    $recoveredWords = [...$recoveredWords, ...$words];
                    $decoded[] = [$start, $end];
                }

                $attempts[] = ['start' => $start, 'end' => $end, 'words' => $cues === [] ? 0 : count($words),
                    'cues' => count($cues), 'recorded_at' => now()->toIso8601String(),
                    'suspect_cues' => array_values(array_filter($suspect, static fn (array $cue): bool => $cue['start'] < $end && $cue['end'] > $start))];
            }
        } finally {
            if ($isDownloaded) {
                $this->storage->cleanupTempFile($local);
            }
        }

        $replaced = array_values(array_filter($suspect, fn (array $cue): bool => $this->isReplaced($cue, $recoveredWords, $decoded, $timeline)));

        foreach ($attempts as &$attempt) {
            $attempt['replaced_cues'] = array_values(array_filter($replaced, static fn (array $cue): bool => $cue['start'] < $attempt['end'] && $cue['end'] > $attempt['start']));
        }
        unset($attempt);

        $log->writeProcessingMetadata(static function (array $metadata) use ($attempts): array {
            $metadata[self::METADATA_KEY] = $attempts;

            return $metadata;
        });

        if ($recoveredCues === []) {
            return ['stretches' => count($stretches), 'recovered' => 0];
        }

        $kept = array_values(array_filter($transcript->cues, static fn (array $cue): bool => ! in_array($cue, $replaced, true)));
        $recovered = ChurchServiceTranscript::fromCues([...$kept, ...$recoveredCues], $transcript->duration, $transcript->source, $transcript->unobservableWindows);
        $path = $this->artifacts->putJson($log->processing_id, self::ARTIFACT_KIND, $recovered->toArray());
        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($recovered);
        $log->refresh();
        $log->putServiceTranscriptPath($path, $recovered->unobservableWindows, array_map(static fn (SuspectTranscriptBlock $block): array => $block->toArray(), $blocks));
        $log->recordServiceTranscriptContent(MediaProcessingLog::hashServiceTranscriptContent($recovered));

        return ['stretches' => count($stretches), 'recovered' => count(array_filter($attempts, static fn (array $attempt): bool => $attempt['cues'] > 0))];
    }

    /**
     * A cue that is no evidence of speech gives way to the decode beneath it when the decode says
     * its words, or when every stretch of speech beneath it was decoded to words: a cue of
     * punctuation, or "Thank you." stretched over a hymn's outro. Speech beneath it the decode
     * did not reach may be where its words are, so it stays.
     *
     * @param  array{start: float, end: float, text: string}  $cue
     * @param  list<array{start: float, end: float, word: string}>  $words  Every recovered word
     * @param  list<array{0: float, 1: float}>  $decoded  The stretches that recovered words
     */
    private function isReplaced(array $cue, array $words, array $decoded, AudioTimeline $timeline): bool
    {
        $beneath = array_values(array_filter($words, static fn (array $word): bool => $word['start'] < $cue['end'] + self::DECODE_MARGIN_SECONDS
            && $word['end'] > $cue['start'] - self::DECODE_MARGIN_SECONDS));

        if ($beneath === []) {
            return false;
        }

        $heard = TranscriptCueEvidence::tokens(implode(' ', array_column($beneath, 'word')));
        $position = 0;

        foreach (TranscriptCueEvidence::tokens($cue['text']) as $token) {
            $found = array_search($token, array_slice($heard, $position), true);

            if ($found === false) {
                $position = -1;

                break;
            }

            $position += $found + 1;
        }

        if ($position >= 0) {
            return true;
        }

        foreach ($timeline->windows as $window) {
            $from = max($window['start'], $cue['start']);
            $to = min($window['end'], $cue['end']);

            if ($window['speech'] >= self::MINIMUM_SPEECH_SCORE && $to > $from
                && ! array_any($decoded, static fn (array $stretch): bool => $stretch[0] <= $from + 0.01 && $stretch[1] >= $to - 0.01)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs of speech windows no evidence cue or recorded unobservable window touches, at least the
     * floor long.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function stretches(ChurchServiceTranscript $transcript, AudioTimeline $timeline): array
    {
        $covered = [...array_map(static fn (array $cue): array => [$cue['start'], $cue['end']], array_filter($transcript->cues, static fn (array $cue): bool => TranscriptCueEvidence::isEvidence($cue, $timeline))),
            ...array_map(static fn (array $window): array => [$window['start'], $window['end']], $transcript->unobservableWindows)];
        $runs = [];

        foreach ($timeline->windows as $window) {
            if ($window['speech'] < self::MINIMUM_SPEECH_SCORE) {
                continue;
            }

            $last = count($runs) - 1;

            if ($last >= 0 && abs($runs[$last][1] - $window['start']) < 0.01) {
                $runs[$last][1] = $window['end'];
            } else {
                $runs[] = [$window['start'], $window['end']];
            }
        }

        $stretches = [];

        foreach ($runs as [$from, $to]) {
            foreach ($this->uncovered($from, $to, $covered) as [$start, $end]) {
                if ($end - $start >= self::MINIMUM_STRETCH_SECONDS) {
                    $stretches[] = [$start, $end];
                }
            }
        }

        return $stretches;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $covered
     * @return list<array{0: float, 1: float}>
     */
    private function uncovered(float $from, float $to, array $covered): array
    {
        usort($covered, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $pieces = [];
        $cursor = $from;

        foreach ($covered as [$start, $end]) {
            if ($end <= $cursor || $start >= $to) {
                continue;
            }

            if ($start > $cursor) {
                $pieces[] = [$cursor, min($start, $to)];
            }

            $cursor = max($cursor, $end);
        }

        if ($cursor < $to) {
            $pieces[] = [$cursor, $to];
        }

        return $pieces;
    }

    /**
     * The words heard in the stretch, in service time; empty when the decode fails or hears none.
     *
     * @return list<array{start: float, end: float, word: string}>
     */
    private function decode(MediaProcessingLog $log, string $audio, float $start, float $end, float $duration): array
    {
        $from = max(0.0, $start - self::DECODE_MARGIN_SECONDS);
        $to = min($duration > 0.0 ? $duration : $end + self::DECODE_MARGIN_SECONDS, $end + self::DECODE_MARGIN_SECONDS);

        try {
            $clip = $this->extractor->extract($audio, $from, $to, $log->processing_id);

            try {
                $words = $this->transcription->transcribeEdgeWindow($clip);
            } finally {
                $this->extractor->delete($clip);
            }
        } catch (\Throwable $exception) {
            Log::warning('Untranscribed speech could not be decoded again', [
                'processing_id' => $log->processing_id, 'start' => $start, 'end' => $end, 'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $heard = [];

        foreach ($words as $word) {
            $wordStart = $word['start'] + $from;
            $wordEnd = $word['end'] + $from;
            $middle = ($wordStart + $wordEnd) / 2;

            if ($middle >= $start && $middle <= $end && preg_match('/[\p{L}\p{N}]/u', $word['word']) === 1) {
                $heard[] = ['start' => $wordStart, 'end' => $wordEnd, 'word' => $word['word']];
            }
        }

        $tokens = array_map(static fn (array $word): string => mb_strtolower(trim($word['word'], " \t\n.,!?;:")), $heard);

        if (count($tokens) >= 4 && count(array_unique($tokens)) / count($tokens) < self::MINIMUM_DISTINCT_SHARE) {
            return [];
        }

        return $heard;
    }

    /**
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cues(array $words): array
    {
        $cues = [];
        $current = null;

        foreach ($words as $word) {
            if ($current !== null && ($word['start'] - $current['end'] > self::CUE_BREAK_SECONDS || $word['end'] - $current['start'] > self::LONGEST_CUE_SECONDS)) {
                $cues[] = ['start' => $current['start'], 'end' => $current['end'], 'text' => trim($current['text'])];
                $current = null;
            }

            $current = $current === null
                ? ['start' => $word['start'], 'end' => $word['end'], 'text' => $word['word']]
                : ['start' => $current['start'], 'end' => $word['end'], 'text' => $current['text'].$word['word']];
        }

        if ($current !== null) {
            $cues[] = ['start' => $current['start'], 'end' => $current['end'], 'text' => trim($current['text'])];
        }

        return $cues;
    }
}
