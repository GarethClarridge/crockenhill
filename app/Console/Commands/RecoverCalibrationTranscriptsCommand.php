<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\ServiceTranscriptRecovery;
use App\Support\CanonicalJson;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Re-run transcript recovery over banked calibration artifacts, offline.
 *
 * IC5 item 6a freezes the calibration inputs, but the pathology detector landed
 * after they were banked, so the banked transcripts no longer match what the
 * definitive run will produce. This command closes that gap the cheap way: it
 * reads a banked transcript and its banked audio straight off the drive, runs
 * the same `ServiceTranscriptRecovery` the pipeline runs, and writes the result
 * to a new private artifact.
 *
 * It deliberately does not go through the importer. Re-running the six
 * calibration identities end to end would mean bypassing the completed-item
 * guard and minting fresh processing records for work that is already done —
 * a permanent hole in a safety control, opened for a one-off. Nothing here
 * touches the database, the manifest, or the banked inputs; the definitive
 * slice-5 run performs the same recovery inline for all 470 identities.
 *
 * Delete after historic-import IC8 closeout.
 */
class RecoverCalibrationTranscriptsCommand extends Command
{
    protected $signature = 'historic-import:recover-calibration-transcripts
        {--manifest= : Private manifest of banked transcript/audio pairs}
        {--output= : New permission-restricted JSON report path}';

    protected $description = 'Re-run transcript recovery over banked calibration artifacts without reprocessing';

    public function handle(ServiceTranscriptRecovery $recovery): int
    {
        try {
            if (app()->environment('production')) {
                throw new RuntimeException('Calibration transcript recovery is available only on a rehearsal environment.');
            }

            $manifestPath = PrivateEvidenceFile::resolve($this->option('manifest'), 'The calibration recovery manifest');
            $reportPath = PrivateEvidenceFile::resolve($this->option('output'), 'The calibration recovery report');
            $identities = $this->identities($manifestPath);
            $results = [];

            foreach ($identities as $identity) {
                $results[] = $this->recoverIdentity($recovery, $identity, dirname($reportPath));
            }

            $report = [
                'format' => 'crockenhill-calibration-transcript-recovery',
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'processing_records_created' => false,
                'results' => $results,
            ];

            PrivateEvidenceFile::writeOnce(
                $reportPath,
                CanonicalJson::encodeReadable($report).PHP_EOL,
                'The calibration recovery report',
            );

            foreach ($results as $result) {
                $this->line(sprintf(
                    '%-22s %-12s %d -> %d cue(s), %d unobservable window(s)',
                    $result['label'],
                    $result['disposition'],
                    $result['input_cue_count'],
                    $result['recovered_cue_count'],
                    count($result['unobservable_windows']),
                ));
            }

            $this->info('Recovery complete. Banked inputs unchanged; no processing records created.');
            $this->line("Report: {$reportPath}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array{label: string, transcript_path: string, audio_path: string}  $identity
     * @return array<string, mixed>
     */
    private function recoverIdentity(ServiceTranscriptRecovery $recovery, array $identity, string $outputDirectory): array
    {
        $transcriptPath = $identity['transcript_path'];
        $audioPath = $identity['audio_path'];
        $payload = json_decode((string) file_get_contents($transcriptPath), true);

        if (! is_array($payload)) {
            throw new RuntimeException("Banked transcript {$transcriptPath} is not readable JSON.");
        }

        $transcript = ChurchServiceTranscript::fromArray($payload);
        $recovered = $recovery->recover($transcript, $audioPath, 'calibration-recovery-'.$identity['label']);

        $recoveredPath = $outputDirectory.'/'.$identity['label'].'.recovered.json';
        PrivateEvidenceFile::writeOnce(
            $recoveredPath,
            CanonicalJson::encodeReadable($recovered->toArray()).PHP_EOL,
            'The recovered calibration transcript',
        );

        return [
            'label' => $identity['label'],
            'disposition' => $this->dispositionFor($transcript, $recovered),
            'input_path' => $transcriptPath,
            'input_sha256' => hash_file('sha256', $transcriptPath),
            'input_cue_count' => count($transcript->cues),
            'recovered_path' => $recoveredPath,
            'recovered_sha256' => hash_file('sha256', $recoveredPath),
            'recovered_cue_count' => count($recovered->cues),
            'unobservable_windows' => $recovered->unobservableWindows,
        ];
    }

    private function dispositionFor(ChurchServiceTranscript $before, ChurchServiceTranscript $after): string
    {
        if ($after->unobservableWindows !== []) {
            return 'unobservable';
        }

        return $before->cues === $after->cues ? 'unchanged' : 'recovered';
    }

    /** @return list<array{label: string, transcript_path: string, audio_path: string}> */
    private function identities(string $manifestPath): array
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest) || ! is_array($manifest['identities'] ?? null) || $manifest['identities'] === []) {
            throw new RuntimeException('The calibration recovery manifest must list at least one identity.');
        }

        return array_map(static function (mixed $identity): array {
            if (! is_array($identity)) {
                throw new RuntimeException('Every calibration recovery identity must be an object.');
            }

            foreach (['label', 'transcript_path', 'audio_path'] as $key) {
                if (! is_string($identity[$key] ?? null) || trim($identity[$key]) === '') {
                    throw new RuntimeException("Every calibration recovery identity requires a {$key}.");
                }
            }

            foreach (['transcript_path', 'audio_path'] as $key) {
                if (! is_file($identity[$key]) || ! is_readable($identity[$key])) {
                    throw new RuntimeException("Calibration recovery input {$identity[$key]} is not a readable file.");
                }
            }

            return [
                'label' => $identity['label'],
                'transcript_path' => $identity['transcript_path'],
                'audio_path' => $identity['audio_path'],
            ];
        }, array_values($manifest['identities']));
    }
}
