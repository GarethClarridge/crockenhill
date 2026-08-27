<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\Video\HistoricVideoReencodeConcatenator;
use App\Support\CanonicalJson;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Exercise the historic-video re-encode concat without dispatching or admitting derivative bytes.
 *
 * Delete after historic-import IC8 closeout.
 */
class ProveHistoricVideoReencodeFallbackCommand extends Command
{
    protected $signature = 'historic-import:prove-video-reencode-fallback
        {--input=* : Temporary source-derived clips in concatenation order}
        {--report= : New permission-restricted JSON report below storage/app/private}';

    protected $description = 'Prove the isolated historic-video codec-mismatch re-encode fallback';

    public function handle(HistoricVideoReencodeConcatenator $concatenator): int
    {
        $outputPath = null;

        try {
            $inputs = $this->inputs();
            $fingerprints = array_map(fn (string $path): string => $this->codecFingerprint($path), $inputs);

            if (count(array_unique($fingerprints)) < 2) {
                throw new RuntimeException('The proof inputs are codec-compatible; at least one temporary derivative must have a different stream fingerprint.');
            }

            $outputPath = storage_path('app/private/historic-temp/reencode-proof-'.bin2hex(random_bytes(8)).'.mp4');
            $this->ensureOutputDirectory($outputPath);
            $concatenator->concatenate($inputs, $outputPath);
            $outputFingerprint = $this->codecFingerprint($outputPath);
            $reportPath = PrivateEvidenceFile::resolve($this->option('report'), 'The re-encode fallback proof report');
            $report = [
                'format' => 'crockenhill-historic-video-reencode-proof',
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'processing_state_created' => false,
                'inputs' => array_map(static fn (string $path, string $fingerprint): array => [
                    'basename' => basename($path),
                    'sha256' => hash_file('sha256', $path),
                    'byte_size' => filesize($path),
                    'codec_fingerprint' => $fingerprint,
                ], $inputs, $fingerprints),
                'output' => [
                    'sha256' => hash_file('sha256', $outputPath),
                    'byte_size' => filesize($outputPath),
                    'codec_fingerprint' => $outputFingerprint,
                ],
                'result' => 'concat_reencoded',
            ];

            PrivateEvidenceFile::writeOnce(
                $reportPath,
                CanonicalJson::encodeReadable($report).PHP_EOL,
                'The re-encode fallback proof report',
            );

            $this->info('Re-encode fallback proved without dispatching processing work.');
            $this->line("Report: {$reportPath}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_string($outputPath) && is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    /** @return list<string> */
    private function inputs(): array
    {
        $inputs = $this->option('input');

        if (count($inputs) < 2) {
            throw new RuntimeException('The re-encode fallback proof requires at least two --input clips.');
        }

        return array_map(static function (mixed $path): string {
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Every re-encode fallback proof input must be a readable file.');
            }

            return (string) realpath($path);
        }, array_values($inputs));
    }

    private function codecFingerprint(string $path): string
    {
        $ffprobePath = (string) config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        $command = escapeshellarg($ffprobePath)
            .' -v error -show_entries stream=codec_type,codec_name,width,height,r_frame_rate,sample_rate'
            .' -of json '.escapeshellarg($path);
        $output = shell_exec($command);
        $decoded = is_string($output) ? json_decode($output, true) : null;

        if (! is_array($decoded) || ! is_array($decoded['streams'] ?? null)) {
            throw new RuntimeException("Unable to probe temporary proof input {$path}.");
        }

        return CanonicalJson::hash($decoded['streams']);
    }

    private function ensureOutputDirectory(string $outputPath): void
    {
        $directory = dirname($outputPath);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create private proof directory {$directory}.");
        }
    }
}
