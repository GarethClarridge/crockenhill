<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Support\CanonicalJson;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Measure whether whole-service priming causes transcript loops, corpus-wide.
 *
 * IC5 item 6 proved on one clip that `transcription.prompts.full_service` makes
 * the model emit service-shaped text over non-speech, and recovery stopped
 * inheriting it. It left open the larger question: if priming *induces* these
 * loops, the production prompt may be generating artefacts across all 470
 * identities rather than merely defeating their repair.
 *
 * The four windows recovery already examined cannot answer that. They were
 * selected because the primed run failed there, so comparing arms on them
 * selects on the outcome. This command instead re-transcribes whole banked
 * recordings — exactly the production shape — under both prompts, with
 * replicates, and replays the same detector over every draw.
 *
 * Replicates are not optional. The banked corpus already contains four primed
 * passes over byte-identical `2024-01-14` audio whose cue counts are 130,
 * 2157, 2776 and 3651: run-to-run variance under a fixed prompt spans the whole
 * range any prompt effect could occupy, so a single paired draw would be
 * uninterpretable. Arms are interleaved within each draw so machine drift
 * lands on both.
 *
 * Nothing here touches the database, the manifest or the banked inputs, and
 * local Whisper carries no API charge.
 *
 * Delete after historic-import IC8 closeout.
 */
class MeasureTranscriptionPrimingCommand extends Command
{
    protected $signature = 'historic-import:measure-transcription-priming
        {--manifest= : Private manifest of banked audio to probe}
        {--output= : New permission-restricted JSON report path}
        {--draws=3 : Replicate draws per identity per arm}';

    protected $description = 'Measure whether the full-service prompt induces transcript loops, with replicates';

    private const PRODUCTION_PROMPT = null;

    private const NO_PROMPT = '';

    public function handle(
        ServiceTranscriptionInterface $transcription,
        ServiceTranscriptPathologyDetector $detector,
    ): int {
        try {
            if (app()->environment('production')) {
                throw new RuntimeException('Priming measurement is available only on a rehearsal environment.');
            }

            $manifestPath = PrivateEvidenceFile::resolve($this->option('manifest'), 'The priming measurement manifest');
            $reportPath = PrivateEvidenceFile::resolve($this->option('output'), 'The priming measurement report');
            $draws = (int) $this->option('draws');

            if ($draws < 1) {
                throw new RuntimeException('At least one draw per arm is required.');
            }

            $identities = $this->identities($manifestPath);
            $runs = [];

            for ($draw = 1; $draw <= $draws; $draw++) {
                foreach ($identities as $identity) {
                    foreach (['primed' => self::PRODUCTION_PROMPT, 'unprimed' => self::NO_PROMPT] as $arm => $prompt) {
                        $runs[] = $this->draw($transcription, $detector, $identity, $arm, $prompt, $draw, dirname($reportPath));
                    }
                }
            }

            $report = [
                'format' => 'crockenhill-transcription-priming-measurement',
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'draws_per_arm' => $draws,
                'processing_records_created' => false,
                'transcription_service' => $transcription::class,
                'model' => (string) config('media-processing.transcription.local_whisper_model'),
                'detector_thresholds' => config('media-processing.service_structure.transcript_recovery'),
                'primed_prompt' => (string) config('media-processing.transcription.prompts.full_service'),
                'runs' => $runs,
                'summary' => $this->summarise($runs),
            ];

            PrivateEvidenceFile::writeOnce(
                $reportPath,
                CanonicalJson::encodeReadable($report).PHP_EOL,
                'The priming measurement report',
            );

            $this->renderSummary($report['summary']);
            $this->line("Report: {$reportPath}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array{label: string, audio_path: string}  $identity
     * @return array<string, mixed>
     */
    private function draw(
        ServiceTranscriptionInterface $transcription,
        ServiceTranscriptPathologyDetector $detector,
        array $identity,
        string $arm,
        ?string $prompt,
        int $draw,
        string $outputDirectory,
    ): array {
        $processingId = sprintf('priming-%s-%s-%d', $identity['label'], $arm, $draw);
        $startedAt = microtime(true);
        $transcript = $transcription->transcribeService($identity['audio_path'], $processingId, $prompt);
        $elapsed = microtime(true) - $startedAt;

        $transcriptPath = $outputDirectory.'/'.$processingId.'.json';
        PrivateEvidenceFile::writeOnce(
            $transcriptPath,
            CanonicalJson::encodeReadable($transcript->toArray()).PHP_EOL,
            'The priming measurement transcript',
        );

        $windows = $detector->detect($transcript);
        $repetition = $this->repetitionCensus($transcript);

        $this->line(sprintf(
            '%-22s %-9s draw %d  %5d cues  %d window(s)  %5.1f%% repeated  %.0fs',
            $identity['label'],
            $arm,
            $draw,
            count($transcript->cues),
            count($windows),
            $repetition['repeated_cue_share'],
            $elapsed,
        ));

        return [
            'label' => $identity['label'],
            'arm' => $arm,
            'draw' => $draw,
            'audio_sha256' => hash_file('sha256', $identity['audio_path']),
            'transcript_path' => $transcriptPath,
            'transcript_sha256' => hash_file('sha256', $transcriptPath),
            'elapsed_seconds' => round($elapsed, 1),
            'duration_seconds' => round($transcript->duration, 1),
            'cue_count' => count($transcript->cues),
            'detector_window_count' => count($windows),
            'detector_flagged_seconds' => round(array_sum(array_map(
                static fn (array $window): float => $window['end'] - $window['start'],
                $windows,
            )), 1),
            'detector_windows' => $windows,
            'repetition' => $repetition,
        ];
    }

    /**
     * Count repetition below the detector's floors as well as above them.
     *
     * The detector answers "would recovery fire here". The corpus-wide question
     * is different: whether priming shifts how much of the transcript is
     * looped at all, including runs too short or too fast for recovery to
     * notice. Both numbers are reported so a null detector result cannot hide
     * a real shift.
     *
     * @return array{repeat_runs: int, repeated_cues: int, repeated_seconds: float, repeated_cue_share: float, longest_run: int, longest_run_text: string}
     */
    private function repetitionCensus(ChurchServiceTranscript $transcript): array
    {
        $runs = [];
        $run = [];
        $previous = null;

        foreach ($transcript->cues as $cue) {
            $text = $this->normalise($cue['text']);

            if ($text !== '' && $text === $previous) {
                $run[] = $cue;
            } else {
                if (count($run) >= 2) {
                    $runs[] = $run;
                }

                $run = $text === '' ? [] : [$cue];
            }

            $previous = $text;
        }

        if (count($run) >= 2) {
            $runs[] = $run;
        }

        $repeatedCues = 0;
        $repeatedSeconds = 0.0;
        $longest = [];

        foreach ($runs as $candidate) {
            $repeatedCues += count($candidate);
            $repeatedSeconds += $candidate[count($candidate) - 1]['end'] - $candidate[0]['start'];

            if (count($candidate) > count($longest)) {
                $longest = $candidate;
            }
        }

        return [
            'repeat_runs' => count($runs),
            'repeated_cues' => $repeatedCues,
            'repeated_seconds' => round($repeatedSeconds, 1),
            'repeated_cue_share' => $transcript->cues === []
                ? 0.0
                : round(100 * $repeatedCues / count($transcript->cues), 2),
            'longest_run' => count($longest),
            'longest_run_text' => $longest === [] ? '' : trim($longest[0]['text']),
        ];
    }

    private function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function summarise(array $runs): array
    {
        $byArm = [];

        foreach (['primed', 'unprimed'] as $arm) {
            $armRuns = array_values(array_filter($runs, static fn (array $run): bool => $run['arm'] === $arm));

            $byArm[$arm] = [
                'draws' => count($armRuns),
                'draws_with_detector_window' => count(array_filter(
                    $armRuns,
                    static fn (array $run): bool => $run['detector_window_count'] > 0,
                )),
                'total_detector_windows' => (int) array_sum(array_column($armRuns, 'detector_window_count')),
                'total_detector_flagged_seconds' => round((float) array_sum(array_column($armRuns, 'detector_flagged_seconds')), 1),
                'mean_repeated_cue_share' => $armRuns === [] ? 0.0 : round(
                    array_sum(array_column(array_column($armRuns, 'repetition'), 'repeated_cue_share')) / count($armRuns),
                    2,
                ),
                'total_repeated_seconds' => round((float) array_sum(array_column(array_column($armRuns, 'repetition'), 'repeated_seconds')), 1),
                'mean_cue_count' => $armRuns === [] ? 0.0 : round(array_sum(array_column($armRuns, 'cue_count')) / count($armRuns), 1),
            ];
        }

        $perIdentity = [];

        foreach ($runs as $run) {
            $perIdentity[$run['label']][$run['arm']][] = $run;
        }

        $identitySummaries = [];

        foreach ($perIdentity as $label => $arms) {
            $identitySummaries[$label] = [];

            foreach ($arms as $arm => $armRuns) {
                $shares = array_column(array_column($armRuns, 'repetition'), 'repeated_cue_share');
                $cues = array_column($armRuns, 'cue_count');

                $identitySummaries[$label][$arm] = [
                    'draws_with_detector_window' => count(array_filter(
                        $armRuns,
                        static fn (array $run): bool => $run['detector_window_count'] > 0,
                    )),
                    'repeated_cue_share' => array_map(static fn (float $share): float => $share, $shares),
                    'cue_counts' => $cues,
                    'cue_count_spread' => $cues === [] ? 0 : max($cues) - min($cues),
                ];
            }
        }

        return ['by_arm' => $byArm, 'by_identity' => $identitySummaries];
    }

    /** @param array<string, mixed> $summary */
    private function renderSummary(array $summary): void
    {
        $rows = [];

        foreach ($summary['by_arm'] as $arm => $row) {
            $rows[] = [
                $arm,
                $row['draws'],
                $row['draws_with_detector_window'],
                $row['total_detector_windows'],
                $row['total_detector_flagged_seconds'],
                $row['mean_repeated_cue_share'],
                $row['total_repeated_seconds'],
                $row['mean_cue_count'],
            ];
        }

        $this->newLine();
        $this->table(
            ['Arm', 'Draws', 'Draws flagged', 'Windows', 'Flagged s', 'Mean repeat %', 'Repeated s', 'Mean cues'],
            $rows,
        );
    }

    /** @return list<array{label: string, audio_path: string}> */
    private function identities(string $manifestPath): array
    {
        $payload = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($payload) || ! is_array($payload['identities'] ?? null) || $payload['identities'] === []) {
            throw new RuntimeException("Priming manifest {$manifestPath} declares no identities.");
        }

        $identities = [];

        foreach ($payload['identities'] as $identity) {
            if (! is_array($identity) || ! is_string($identity['label'] ?? null) || ! is_string($identity['audio_path'] ?? null)) {
                throw new RuntimeException('Every priming manifest identity needs a label and an audio_path.');
            }

            if (! is_file($identity['audio_path'])) {
                throw new RuntimeException("Banked audio {$identity['audio_path']} is not readable.");
            }

            $identities[] = ['label' => $identity['label'], 'audio_path' => $identity['audio_path']];
        }

        return $identities;
    }
}
