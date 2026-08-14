<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Enums\HistoricVideoCorroborationGrade;
use App\Enums\SermonService;
use FFMpeg\FFProbe;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Stage one of video curation: enumerate the corpus so the operator can
 * adjudicate it.
 *
 * This deliberately does **not** hash. Content hashes are what make the final
 * manifest authority, but nothing the operator decides here — grouping,
 * identity, include/exclude, editorial facts — needs one, and hashing the real
 * corpus means reading roughly a terabyte at about 88 MB/s. Paying that at the
 * frequency of an editing pass would be absurd, and worse, re-running the draft
 * to pick up a corpus change would overwrite the adjudication it took hours to
 * produce.
 *
 * So the expensive artifact and the editable artifact are kept apart: this
 * writes a worksheet, the operator adjudicates it, and
 * {@see HistoricVideoCurationCapture} hashes once at freeze.
 *
 * Sizes come free from `stat`, and ffprobe reads container headers rather than
 * whole files, so a complete worksheet costs minutes.
 */
class HistoricVideoCurationDraft
{
    public const WORKSHEET_FORMAT = 'crockenhill-historic-video-curation-worksheet';

    public const WORKSHEET_VERSION = 1;

    /** Recording containers the importer can take, mirroring the validator. */
    private const SUPPORTED_EXTENSIONS = ['avi', 'mkv', 'mov', 'mp4', 'webm'];

    /**
     * The corpus layout the reorg of 2026-07-30/31 established: one directory
     * per service date, split into the two Sunday services.
     */
    private const IDENTITY_PATTERN = '#\A(?<date>\d{4}-\d{2}-\d{2})/(?<service>Morning|Evening)/[^/]+\z#';

    /**
     * @return array{
     *     format:string,
     *     version:int,
     *     batch_key:string,
     *     entries:list<array<string, mixed>>
     * }
     */
    public function draft(
        string $rawDirectory,
        string $batchKey,
        string $ruleVersion,
        bool $probeDurations,
        ?callable $onIdentityDrafted = null,
    ): array {
        $rawRoot = realpath($rawDirectory);

        if (! is_string($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException("Historic video directory does not exist: {$rawDirectory}");
        }

        $grouped = $this->groupByServiceIdentity($rawRoot);
        $entries = [];

        foreach ($grouped as $identity => $relativePaths) {
            [$date, $service] = explode('|', $identity);
            $entries[] = $this->entry($rawRoot, $date, $service, $relativePaths, $ruleVersion, $probeDurations);

            if ($onIdentityDrafted !== null) {
                $onIdentityDrafted(count($entries), count($grouped));
            }
        }

        return [
            'format' => self::WORKSHEET_FORMAT,
            'version' => self::WORKSHEET_VERSION,
            'batch_key' => $batchKey,
            'entries' => $entries,
        ];
    }

    /**
     * Every candidate recording must resolve to a date and a service, because
     * the manifest schema has no way to represent one that does not — an
     * unplaceable file would otherwise be silently dropped from a corpus whose
     * whole point is that nothing is unaccounted for.
     *
     * @return array<string, list<string>>
     */
    private function groupByServiceIdentity(string $rawRoot): array
    {
        $grouped = [];
        $unplaceable = [];

        foreach ($this->candidateRecordings($rawRoot) as $relativePath) {
            if (preg_match(self::IDENTITY_PATTERN, $relativePath, $matches) !== 1) {
                $unplaceable[] = $relativePath;

                continue;
            }

            $service = $matches['service'] === 'Morning' ? SermonService::Morning : SermonService::Evening;
            $grouped["{$matches['date']}|{$service->value}"][] = $relativePath;
        }

        if ($unplaceable !== []) {
            sort($unplaceable, SORT_STRING);

            throw new RuntimeException(
                'Historic video directory holds recordings with no derivable date and service; '
                .'place or quarantine them before drafting: '.implode(', ', array_slice($unplaceable, 0, 20))
                .(count($unplaceable) > 20 ? ' (+'.(count($unplaceable) - 20).' more)' : ''),
            );
        }

        ksort($grouped, SORT_STRING);

        foreach ($grouped as $identity => $paths) {
            sort($paths, SORT_STRING);
            $grouped[$identity] = $paths;
        }

        return $grouped;
    }

    /**
     * Mirrors the validator's completeness sweep, which ignores the metadata
     * both macOS and Windows scatter through a removable drive.
     *
     * @return list<string>
     */
    private function candidateRecordings(string $rawRoot): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rawRoot, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($rawRoot) + 1));

            foreach (explode('/', $relativePath) as $segment) {
                if (str_starts_with($segment, '.')) {
                    continue 2;
                }
            }

            if (! in_array(strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $paths[] = $relativePath;
        }

        return $paths;
    }

    /**
     * @param  list<string>  $relativePaths
     * @return array<string, mixed>
     */
    private function entry(
        string $rawRoot,
        string $date,
        string $service,
        array $relativePaths,
        string $ruleVersion,
        bool $probeDurations,
    ): array {
        $files = [];
        $totalMinutes = $probeDurations ? 0.0 : null;

        foreach ($relativePaths as $relativePath) {
            $absolute = $rawRoot.'/'.$relativePath;
            $size = filesize($absolute);

            if (! is_int($size) || $size < 1) {
                throw new RuntimeException("Historic video source could not be read: {$relativePath}");
            }

            $minutes = $probeDurations ? $this->durationMinutes($absolute) : null;
            $files[] = [
                'relative_path' => $relativePath,
                'byte_size' => $size,
                'duration_minutes' => $minutes === null ? null : round($minutes, 2),
            ];

            if ($probeDurations && $totalMinutes !== null) {
                $totalMinutes = $minutes === null ? null : $totalMinutes + $minutes;
            }
        }

        return [
            'item_key' => "{$date}-{$service}",
            'source_kind' => 'livestream',
            'disposition' => 'include',
            'exclusion_reason' => null,
            'duplicate_of' => null,
            'date' => $date,
            'service' => $service,
            'concatenation' => count($files) === 1 ? 'single' : 'lossless',
            'client_file_date' => "{$date} 12:00:00",
            'expected_occurrence_count' => count($files),
            'corroboration' => HistoricVideoCorroborationGrade::forRecording(count($files), $totalMinutes)->value,
            'decision' => ['approved_rule_version' => $ruleVersion],
            'editorial_facts' => [
                'occasion' => null,
                'title' => null,
                'speaker' => null,
                'scripture_reference' => null,
                'series' => null,
            ],
            'files' => $files,
        ];
    }

    /**
     * An unreadable duration downgrades the grade to unknown rather than
     * failing the draft, because a corrupt or exotic container is a curation
     * fact the operator should see in context, not a reason to abandon the run.
     */
    private function durationMinutes(string $absolutePath): ?float
    {
        try {
            $probe = FFProbe::create([
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);
            $duration = $probe->format($absolutePath)->get('duration');

            if (is_numeric($duration) && (float) $duration > 0.0) {
                return (float) $duration / 60.0;
            }
        } catch (Throwable) {
            // Fall through to the packet count.
        }

        return $this->durationMinutesFromPacketCount($absolutePath);
    }

    /**
     * The 2020 corpus contains WebM pulled down as YouTube backups, and those
     * carry no duration in either the format or the stream header — the ordinary
     * probe returns N/A for roughly a tenth of the corpus, every one of which is
     * a full-length service that would otherwise be graded "unknown" and quietly
     * excluded from corroboration.
     *
     * Counting packets and dividing by the frame rate recovers the real length.
     * It costs about a second per file because ffprobe reads packet headers
     * rather than decoding, and it reproduces the operator's hand-measured
     * durations exactly.
     */
    private function durationMinutesFromPacketCount(string $absolutePath): ?float
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffprobe_path'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-count_packets',
            '-show_entries', 'stream=nb_read_packets,avg_frame_rate',
            '-of', 'default=noprint_wrappers=1',
            $absolutePath,
        ]);

        try {
            $process->setTimeout(300)->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $values = [];

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', trim($line), 2);
                $values[$key] = $value;
            }
        }

        $packets = $values['nb_read_packets'] ?? null;
        $frameRate = $values['avg_frame_rate'] ?? null;

        if (! is_numeric($packets) || ! is_string($frameRate) || ! str_contains($frameRate, '/')) {
            return null;
        }

        [$numerator, $denominator] = array_map('floatval', explode('/', $frameRate, 2));

        if ($denominator <= 0.0 || $numerator <= 0.0 || (float) $packets <= 0.0) {
            return null;
        }

        return (float) $packets / ($numerator / $denominator) / 60.0;
    }
}
