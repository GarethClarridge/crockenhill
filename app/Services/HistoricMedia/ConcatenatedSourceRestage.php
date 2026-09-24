<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\MediaProcessingLog;
use App\Services\Media\MediaCodecFingerprint;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Rebuild a concatenated historic run's staged source from its archive parts, proving the
 * rebuild carries the timeline the run's transcript and sections were derived from.
 *
 * A single-part source is restored byte-identical (`historic-import:restage-source`). A
 * concatenation cannot be: its bytes are whatever the ffmpeg that joined the parts wrote, and
 * another ffmpeg writes different container bytes over the same streams. Run 950's rebuild
 * matched the run's duration to the millisecond and missed its recorded hash by 495 KB of
 * container overhead. So this checks the two things that establish the timeline instead:
 *
 * 1. every archive part is the file the manifest approved (size and sha256, in manifest order);
 * 2. the rebuilt file, joined with the importer's own recipe, has the run's recorded duration
 *    and codec fingerprint.
 *
 * Where a file is still staged (runs 973 and 1014 kept their original join but never recorded
 * its hash), it is kept only if it carries exactly the rebuild's packets.
 *
 * The staged file's sha256 is then stamped on the run, and that stamp is what later staged-
 * source checks verify against ({@see MediaProcessingLog::stagedSourceFileHash()}). The run's
 * `file_hash` keeps describing the original concatenation, which is provenance, not a gate.
 *
 * Call inside the run's staging context: the staging guard re-roots `temp_disk` at the batch.
 */
final class ConcatenatedSourceRestage
{
    public const STAMP_KEY = 'concatenated_source_restage';

    /**
     * How far the rebuild's duration may sit from the run's recorded duration. The run records
     * milliseconds; run 950's rebuild differed by 0.0003 s. A different part or order moves it
     * by whole seconds, and one dropped frame by 0.033 s.
     */
    public const DURATION_TOLERANCE_SECONDS = 0.01;

    public function __construct(private readonly MediaCodecFingerprint $codecFingerprint) {}

    /**
     * @return array{outcome: 'verified'|'restaged'|'already_staged'|'already_restaged', sha256: string, duration: float, recorded_duration: float, parts: int}
     *
     * @throws RuntimeException naming the check that refused
     */
    public function restage(MediaProcessingLog $run, string $archiveRoot, bool $execute): array
    {
        $import = data_get($run->processing_metadata?->toArray() ?? [], 'historic_import');

        if (! is_array($import) || ($import['concatenation'] ?? null) !== 'lossless') {
            throw new RuntimeException(sprintf(
                'Only a lossless concatenation can be rebuilt from its parts; this run records concatenation %s.',
                var_export(is_array($import) ? ($import['concatenation'] ?? null) : null, true),
            ));
        }

        $recordedDuration = is_numeric($run->duration) ? (float) $run->duration : 0.0;

        if ($recordedDuration <= 0.0) {
            throw new RuntimeException('This run records no duration, so a rebuild cannot be shown to carry its timeline.');
        }

        $target = $run->source_file_path;

        if (! is_string($target) || $target === '') {
            throw new RuntimeException('This run records no source path to restore to.');
        }

        $disk = Storage::disk((string) config('media-processing.storage.temp_disk'));
        $stamp = $run->concatenatedSourceRestage();

        if ($stamp !== null && $disk->exists($target) && hash_file('sha256', $disk->path($target)) === $stamp['sha256']) {
            return [
                'outcome' => 'already_restaged',
                'sha256' => $stamp['sha256'],
                'duration' => $stamp['duration'],
                'recorded_duration' => $recordedDuration,
                'parts' => $stamp['parts'],
            ];
        }

        $parts = $this->verifiedParts($import['sources'] ?? null, $archiveRoot);
        $partial = preg_replace('/\.[^.\/]+$/', '', $target).'.restage-partial.mkv';

        try {
            $disk->makeDirectory(dirname($partial));
            $this->concatenate($parts, $disk->path($partial));

            $duration = $this->duration($disk->path($partial));

            if (abs($duration - $recordedDuration) > self::DURATION_TOLERANCE_SECONDS) {
                throw new RuntimeException(sprintf(
                    'The rebuild runs %.6f s and the run recorded %.3f s, so it is not the timeline this run was processed on.',
                    $duration,
                    $recordedDuration,
                ));
            }

            $recordedCodec = $import['codec_fingerprint'] ?? null;
            $codec = $this->codecFingerprint->for($disk->path($partial));

            if (is_string($recordedCodec) && $recordedCodec !== '' && $codec !== $recordedCodec) {
                throw new RuntimeException(sprintf('The rebuild\'s streams read %s and the run recorded %s.', (string) $codec, $recordedCodec));
            }

            $sha256 = (string) hash_file('sha256', $disk->path($partial));

            if ($disk->exists($target)) {
                // A file already staged either carries exactly the packets these parts join to,
                // which proves it, or it is something this gate cannot vouch for; it is never
                // overwritten. Packets, not bytes: Matroska writes a random segment UID and the
                // date into every file, so even this ffmpeg never writes the same bytes twice.
                $rebuiltStreams = $this->streamHash($disk->path($partial));

                if ($rebuiltStreams === null || $this->streamHash($disk->path($target)) !== $rebuiltStreams) {
                    throw new RuntimeException(sprintf(
                        'A different file is already staged at %s. The parts and timeline check out, but that file does not carry their packets; move it aside to restage.',
                        $target,
                    ));
                }

                // The staged file is the one the run processed; keep it and stamp its own bytes.
                $sha256 = (string) hash_file('sha256', $disk->path($target));

                if ($execute) {
                    $this->stamp($run, $sha256, $duration, $codec, count($parts), 'already_staged');
                }

                return ['outcome' => 'already_staged', 'sha256' => $sha256, 'duration' => $duration, 'recorded_duration' => $recordedDuration, 'parts' => count($parts)];
            }

            if (! $execute) {
                return ['outcome' => 'verified', 'sha256' => $sha256, 'duration' => $duration, 'recorded_duration' => $recordedDuration, 'parts' => count($parts)];
            }

            if (! $disk->move($partial, $target) || hash_file('sha256', $disk->path($target)) !== $sha256) {
                $disk->delete($target);

                throw new RuntimeException('The rebuilt source did not arrive intact at its staged path and has been removed.');
            }

            $this->stamp($run, $sha256, $duration, $codec, count($parts), 'restaged');

            return ['outcome' => 'restaged', 'sha256' => $sha256, 'duration' => $duration, 'recorded_duration' => $recordedDuration, 'parts' => count($parts)];
        } finally {
            $disk->delete($partial);
        }
    }

    /**
     * Resolve each manifest part and prove it is the file the manifest approved. Order is the
     * manifest's: it is the order the importer joined them in.
     *
     * @return list<string> absolute paths, in join order
     */
    private function verifiedParts(mixed $sources, string $archiveRoot): array
    {
        if (! is_array($sources) || count($sources) < 2) {
            throw new RuntimeException('The manifest records fewer than two parts, so there is nothing to join.');
        }

        $paths = [];

        foreach (array_values($sources) as $index => $source) {
            $recordedPath = is_array($source) ? ($source['path'] ?? null) : null;
            $size = is_array($source) ? ($source['size'] ?? null) : null;
            $sha256 = is_array($source) ? ($source['sha256'] ?? null) : null;
            $label = sprintf('Part %d (%s)', $index + 1, is_string($recordedPath) ? $recordedPath : 'no path');

            if (! is_string($recordedPath) || $recordedPath === '' || ! is_int($size) || ! is_string($sha256) || $sha256 === '') {
                throw new RuntimeException("{$label} records no path, size or sha256 to verify against.");
            }

            // Recorded paths are absolute for the calibration corpus and the first two
            // operations, and relative to the archive root for operation 4.
            $path = str_starts_with($recordedPath, '/') ? $recordedPath : rtrim($archiveRoot, '/').'/'.$recordedPath;

            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("{$label} is not readable at {$path}.");
            }

            if (filesize($path) !== $size) {
                throw new RuntimeException(sprintf('%s is %d bytes and the manifest approved %d.', $label, (int) filesize($path), $size));
            }

            if (! hash_equals($sha256, (string) hash_file('sha256', $path))) {
                throw new RuntimeException("{$label} does not hash to the sha256 the manifest approved.");
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * The importer's recipe ({@see \App\Services\Media\Video\HistoricVideoImporter}): the concat
     * demuxer, stream copy, Matroska by extension.
     *
     * @param  list<string>  $parts
     */
    private function concatenate(array $parts, string $outputPath): void
    {
        $list = tempnam(sys_get_temp_dir(), 'concat-restage');

        if ($list === false) {
            throw new RuntimeException('Unable to write the ffmpeg concat list.');
        }

        try {
            file_put_contents($list, implode('', array_map(
                static fn (string $path): string => "file '".str_replace("'", "'\\''", $path)."'\n",
                $parts,
            )));

            $process = new Process([
                (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
                '-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $outputPath,
            ]);
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('FFmpeg could not join the parts: '.trim($process->getErrorOutput()));
            }
        } finally {
            @unlink($list);
        }
    }

    /**
     * A sha256 of each stream's packet data, independent of the container around it.
     */
    private function streamHash(string $path): ?string
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-v', 'error', '-i', $path, '-map', '0', '-c', 'copy', '-f', 'streamhash', '-hash', 'sha256', '-',
        ]);
        $process->setTimeout(null);
        $process->run();
        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output !== '' ? $output : null;
    }

    private function duration(string $path): float
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe'),
            '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);
        $process->run();
        $duration = trim($process->getOutput());

        if (! $process->isSuccessful() || ! is_numeric($duration)) {
            throw new RuntimeException('FFprobe could not read the rebuild\'s duration.');
        }

        return (float) $duration;
    }

    private function stamp(MediaProcessingLog $run, string $sha256, float $duration, ?string $codec, int $parts, string $outcome): void
    {
        $run->writeProcessingMetadata(static function (array $metadata) use ($sha256, $duration, $codec, $parts, $outcome): array {
            $metadata[self::STAMP_KEY] = [
                'sha256' => $sha256,
                'duration' => $duration,
                'codec_fingerprint' => $codec,
                'parts' => $parts,
                'outcome' => $outcome,
                'restaged_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
    }
}
