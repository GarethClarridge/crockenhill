<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\ServiceArtifactDisk;
use App\Support\TranscriptPromptEchoDetector;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * H10b's cheap half: score re-decode artifacts against what they were decoded beside.
 *
 * Read-only: no ffmpeg, no Whisper, no queue. The left side is the run's stored
 * transcript, refused if it changed after the decode, or (for control C1) another
 * decode of the same run. Windows are reported unclassified: the C1 noise floor
 * must be measured before any disagreement threshold is chosen, and repetition is
 * a score, never a flag. The new decode's screen blocks are an overlay only.
 *
 * @phpstan-import-type ComparisonWindow from TranscriptRedecodeComparison
 */
final class TranscriptRedecodeReport
{
    public const SCHEMA = 'h10b-comparison-v1';

    private const WINDOW_SECONDS = 30.0;

    private const SCORING_CODE = [
        'app/Services/Media/Audio/TranscriptRedecodeReport.php',
        'app/Services/Media/Audio/TranscriptRedecodeComparison.php',
        'app/Services/Media/Audio/ServiceTranscriptRepetitionScreen.php',
        'app/Support/TranscriptPromptEchoDetector.php',
    ];

    public function __construct(
        private readonly TranscriptRedecodeComparison $comparison,
        private readonly ServiceTranscriptRepetitionScreen $repetitionScreen,
        private readonly TranscriptPromptEchoDetector $promptEchoDetector,
        private readonly ServiceTranscriptReader $transcriptReader,
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly RmsAnalysisService $rmsAnalysisService,
    ) {}

    /**
     * @return array{
     *     schema: string, mode: 'stored'|'redecode', inputs: list<array{run_id: int, artifact_sha256: string, against_sha256: string|null}>,
     *     code: array<string, string>, runs: list<array<string, mixed>>, unassessable: list<array{run_id: int, reason: string}>
     * }
     */
    public function build(string $inputDir, ?string $againstDir = null): array
    {
        $paths = glob(rtrim($inputDir, '/').'/run-*.json') ?: [];
        sort($paths);

        if ($paths === []) {
            throw new RuntimeException("No re-decode artifacts in {$inputDir}.");
        }

        $inputs = [];
        $runs = [];
        $unassessable = [];

        foreach ($paths as $path) {
            $artifact = $this->readArtifact($path);
            $runId = (int) $artifact['run_id'];
            $againstPath = $againstDir === null ? null : rtrim($againstDir, '/').'/'.basename($path);
            $inputs[] = [
                'run_id' => $runId,
                'artifact_sha256' => (string) hash_file('sha256', $path),
                'against_sha256' => $againstPath !== null && is_file($againstPath) ? (string) hash_file('sha256', $againstPath) : null,
            ];

            $result = $againstPath === null
                ? $this->againstStored($artifact)
                : $this->againstRedecode($artifact, $againstPath);

            if (is_array($result)) {
                $result = $this->onSharedSpan(...$result);
            }

            if (is_string($result)) {
                $unassessable[] = ['run_id' => $runId, 'reason' => $result];

                continue;
            }

            $runs[] = [
                ...$this->score($runId, ...$result),
                'stratum' => $artifact['stored_transcript']['stratum'] ?? null,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'mode' => $againstDir === null ? 'stored' : 'redecode',
            'inputs' => $inputs,
            'code' => $this->codeHashes(),
            'runs' => $runs,
            'strata' => array_count_values(array_map(static fn (array $run): string => (string) $run['stratum'], $runs)),
            'unassessable' => $unassessable,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{0: ChurchServiceTranscript, 1: ChurchServiceTranscript, 2: list<SuspectTranscriptBlock>|null, 3: array<string, mixed>}|string
     */
    private function againstStored(array $artifact): array|string
    {
        $run = MediaProcessingLog::find((int) $artifact['run_id']);

        if (! $run instanceof MediaProcessingLog) {
            return 'run not found';
        }

        $context = $run->historicStagingContext();
        $stored = $context === null
            ? $this->transcriptReader->tryRead($run)
            : $this->stagingContexts->within($context, fn (): ?ChurchServiceTranscript => $this->transcriptReader->tryRead($run));

        if ($stored === null) {
            return 'stored transcript is unavailable';
        }

        if (ServiceTranscriptRedecoder::transcriptHash($stored) !== $artifact['stored_transcript']['sha256']) {
            return 'stored transcript changed after the decode';
        }

        $blocks = $artifact['stored_transcript']['suspect_blocks'];
        $storedBlocks = is_array($blocks)
            ? array_map(static fn (array $block): SuspectTranscriptBlock => SuspectTranscriptBlock::fromArray($block), array_values($blocks))
            : null;

        return [$stored, $this->newTranscript($artifact), $storedBlocks, ['compressed_audio_match' => null]];
    }

    /**
     * Control C1: two decodes of one run with identical options.
     *
     * @param  array<string, mixed>  $artifact
     * @return array{0: ChurchServiceTranscript, 1: ChurchServiceTranscript, 2: null, 3: array<string, mixed>}|string
     */
    private function againstRedecode(array $artifact, string $againstPath): array|string
    {
        if (! is_file($againstPath)) {
            return 'no second decode for this run';
        }

        $against = $this->readArtifact($againstPath);

        if ($against['source']['sha256'] !== $artifact['source']['sha256']) {
            return 'the two decodes read different sources';
        }

        if ($against['decode']['request'] !== $artifact['decode']['request']) {
            return 'the two decodes sent different options';
        }

        return [
            $this->newTranscript($against),
            $this->newTranscript($artifact),
            null,
            ['compressed_audio_match' => $against['compressed_audio']['sha256'] === $artifact['compressed_audio']['sha256']],
        ];
    }

    /**
     * Both sides clipped to the shorter duration, when they differ by less than a window.
     *
     * A decode can stamp its last cue out to the end of the padded final 30 s window,
     * and the stored duration then follows that cue (1007 overran by 26 s; 39 of 69
     * mismatches in the corpus pass ran 0.5-29.8 s, all on the stored side). The
     * source hash already binds the audio, so the shared span is compared and the
     * overrun recorded. A whole window or more apart is not this and stays unassessable.
     *
     * @param  list<SuspectTranscriptBlock>|null  $leftBlocks
     * @param  array<string, mixed>  $binding
     * @return array{0: ChurchServiceTranscript, 1: ChurchServiceTranscript, 2: list<SuspectTranscriptBlock>|null, 3: array<string, mixed>}|string
     */
    private function onSharedSpan(ChurchServiceTranscript $left, ChurchServiceTranscript $new, ?array $leftBlocks, array $binding): array|string
    {
        $overrun = round($left->duration - $new->duration, 3);

        if (abs($overrun) >= self::WINDOW_SECONDS) {
            return sprintf('source durations differ (%.3f s against %.3f s)', $left->duration, $new->duration);
        }

        $shared = min($left->duration, $new->duration);
        $clip = static fn (ChurchServiceTranscript $transcript): ChurchServiceTranscript => ChurchServiceTranscript::fromCues(
            $transcript->cues,
            $shared,
            $transcript->source,
            $transcript->unobservableWindows,
        );

        return [$clip($left), $clip($new), $leftBlocks, $binding + ['duration_overrun' => $overrun]];
    }

    /**
     * @param  list<SuspectTranscriptBlock>|null  $leftBlocks
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    private function score(int $runId, ChurchServiceTranscript $left, ChurchServiceTranscript $new, ?array $leftBlocks, array $binding): array
    {
        $newBlocks = $this->repetitionScreen->screen($new);
        $run = MediaProcessingLog::find($runId);
        $sections = $run instanceof MediaProcessingLog
            ? ServiceSection::query()->where('media_processing_log_id', $runId)->get(['section_type', 'start_time', 'end_time'])->values()->all()
            : [];
        $sound = $run instanceof MediaProcessingLog ? $this->sustainedSound($run) : null;
        $windows = array_map(
            fn (array $window): array => $window + [
                'new_screen_overlap' => array_any($newBlocks, static fn (SuspectTranscriptBlock $block): bool => $block->overlaps($window['start'], $window['end'])),
                'section_type' => $this->sectionTypeFor($sections, $window['start'], $window['end']),
                'sustained_share' => $sound?->share($window['start'], $window['end']),
            ],
            $this->comparison->compare($left, $new, $leftBlocks),
        );
        $distances = array_column($windows, 'token_distance');

        return [
            'run_id' => $runId,
            ...$binding,
            'windows' => $windows,
            'summary' => [
                'windows' => count($windows),
                'differing_windows' => count(array_filter($distances, static fn (float $distance): bool => $distance > 0.0)),
                'mean_distance' => $distances === [] ? 0.0 : round(array_sum($distances) / count($distances), 4),
                'max_distance' => $distances === [] ? 0.0 : max($distances),
                'stored_blocks' => $leftBlocks === null ? null : count($leftBlocks),
                'new_blocks' => count($newBlocks),
            ],
        ];
    }

    /**
     * The type of the section overlapping most of the window, or null where none does.
     *
     * @param  array<int, ServiceSection>  $sections
     */
    private function sectionTypeFor(array $sections, float $start, float $end): ?string
    {
        $best = null;
        $bestOverlap = 0.0;

        foreach ($sections as $section) {
            $overlap = min($end, $section->end_time) - max($start, $section->start_time);

            if ($overlap > $bestOverlap) {
                $best = $section->section_type->value;
                $bestOverlap = $overlap;
            }
        }

        return $best;
    }

    /**
     * Null when the run has no readable RMS log: unknown, not silent.
     */
    private function sustainedSound(MediaProcessingLog $run): ?SustainedSound
    {
        $path = $run->rms_log_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $read = static function () use ($path): ?string {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));

            return $disk->exists($path) ? (string) $disk->get($path) : null;
        };
        $context = $run->historicStagingContext();
        $content = $context === null ? $read() : $this->stagingContexts->within($context, $read);

        return is_string($content) ? SustainedSound::fromRmsLog($content, $this->rmsAnalysisService) : null;
    }

    /**
     * The new decode as the pipeline would have handed it on: prompt echoes are
     * removed before any stored transcript is written, so leaving them in would
     * count the filter as a decoder difference.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function newTranscript(array $artifact): ChurchServiceTranscript
    {
        $decoded = ChurchServiceTranscript::fromArray($artifact['new_transcript']);

        return ChurchServiceTranscript::fromCues(
            array_values(array_filter(
                $decoded->cues,
                fn (array $cue): bool => ! $this->promptEchoDetector->isPromptEcho($cue['text']),
            )),
            $decoded->duration,
            $decoded->source,
            $decoded->unobservableWindows,
        );
    }

    /** @return array<string, mixed> */
    private function readArtifact(string $path): array
    {
        $artifact = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($artifact) || ($artifact['schema'] ?? null) !== ServiceTranscriptRedecoder::SCHEMA) {
            throw new RuntimeException("{$path} is not a ".ServiceTranscriptRedecoder::SCHEMA.' artifact.');
        }

        return $artifact;
    }

    /** @return array<string, string> */
    private function codeHashes(): array
    {
        $hashes = [];

        foreach (self::SCORING_CODE as $file) {
            $hashes[$file] = (string) hash_file('sha256', base_path($file));
        }

        return $hashes;
    }

}
