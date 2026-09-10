<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Console\Commands\RestageHistoricSourceCommand;
use App\Models\MediaProcessingLog;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\PacketCountedMediaDuration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Recover the source duration of a run that never recorded one, by measuring the
 * archive recording its own manifest names.
 *
 * P8-Q17's bounds check needs one number per run, and 35 runs holding 431
 * sections do not have it — so those sections cannot be shown to be inside their
 * media or outside it. That is not a pass. The recordings themselves are still
 * on the archive drive, so the number is recoverable rather than lost, and this
 * recovers it.
 *
 * **Identity is checked before the duration is believed.** "A recording of that
 * service" is not good enough for the same reason
 * {@see RestageHistoricSourceCommand} refuses it: the
 * sections are timed against one specific capture, and a duration measured from
 * a different one would be used to clamp them. The manifest's approved size is
 * the default check and `--verify-hash` proves byte-identity, which costs
 * minutes per file on this drive.
 *
 * Every run this can reach is single-part and unconcatenated, so one archive
 * file answers for one run. A concatenated import is refused rather than
 * guessed at: its processed length is the sum of its parts plus whatever the
 * container did at the joins, and summing the parts here would bank an estimate
 * as a measurement.
 *
 * Deletion trigger: delete once every historic run carries a source duration and
 * the Phase 8 release census has run.
 */
class HistoricSourceDurationBackfill
{
    public const DispositionMeasurable = 'measurable';

    public const DispositionAlreadyRecorded = 'already_recorded';

    public const DispositionUnresolved = 'unresolved';

    public function __construct(
        private readonly ExtractedMediaDurationProbe $durationProbe,
        private readonly PacketCountedMediaDuration $packetCountedDuration,
    ) {}

    /**
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<array{
     *     log_id: int,
     *     processing_id: string,
     *     disposition: string,
     *     archive_path: string|null,
     *     measured_duration: float|null,
     *     measured_by?: 'header'|'packet_count',
     *     reason: string|null
     * }>
     */
    public function inspect(Collection $runs, string $archiveRoot, bool $verifyHash): array
    {
        $entries = [];

        foreach ($runs as $run) {
            $entries[] = $this->assess($run, $archiveRoot, $verifyHash);
        }

        return $entries;
    }

    /**
     * @param  list<array{log_id: int, processing_id: string, disposition: string, archive_path: string|null, measured_duration: float|null, measured_by?: 'header'|'packet_count', reason: string|null}>  $entries
     * @return array{recorded: int, unchanged: int, failures: list<string>}
     */
    public function apply(array $entries): array
    {
        $totals = ['recorded' => 0, 'unchanged' => 0, 'failures' => []];

        foreach ($entries as $entry) {
            if ($entry['disposition'] !== self::DispositionMeasurable) {
                continue;
            }

            try {
                DB::transaction(function () use ($entry, &$totals): void {
                    $run = MediaProcessingLog::query()->whereKey($entry['log_id'])->lockForUpdate()->first();

                    if (! $run instanceof MediaProcessingLog) {
                        throw new RuntimeException('the run disappeared before its duration was recorded');
                    }

                    if (is_numeric($run->duration) && (float) $run->duration > 0.0) {
                        $totals['unchanged']++;

                        return;
                    }

                    $run->forceFill(['duration' => $entry['measured_duration']])->save();
                    $totals['recorded']++;
                });
            } catch (Throwable $exception) {
                $totals['failures'][] = "{$entry['processing_id']}: {$exception->getMessage()}";
            }
        }

        return $totals;
    }

    /**
     * @return array{log_id: int, processing_id: string, disposition: string, archive_path: string|null, measured_duration: float|null, measured_by?: 'header'|'packet_count', reason: string|null}
     */
    private function assess(MediaProcessingLog $run, string $archiveRoot, bool $verifyHash): array
    {
        $unresolved = fn (string $reason, ?string $path = null): array => [
            'log_id' => $run->id,
            'processing_id' => $run->processing_id,
            'disposition' => self::DispositionUnresolved,
            'archive_path' => $path,
            'measured_duration' => null,
            'reason' => $reason,
        ];

        if (is_numeric($run->duration) && (float) $run->duration > 0.0) {
            return [
                'log_id' => $run->id,
                'processing_id' => $run->processing_id,
                'disposition' => self::DispositionAlreadyRecorded,
                'archive_path' => null,
                'measured_duration' => (float) $run->duration,
                'reason' => null,
            ];
        }

        $recorded = $run->recordedArchiveSourcePath();

        if ($recorded === null) {
            return $unresolved('no single-part archive source is recorded for this run');
        }

        /*
         * Resolved by testing for a leading separator, never by blind
         * concatenation: the manifest records some paths absolute and some
         * relative, and prefixing an absolute one yields `/mnt/archive//mnt/…`,
         * which reads exactly like a recording that has been deleted.
         */
        $path = str_starts_with($recorded, '/')
            ? $recorded
            : rtrim($archiveRoot, '/').'/'.$recorded;

        if (! is_file($path) || ! is_readable($path)) {
            return $unresolved('the archive copy is not a readable file', $path);
        }

        $identity = $this->identityFailure($run, $path, $verifyHash);

        if ($identity !== null) {
            return $unresolved($identity, $path);
        }

        $measurement = $this->measure($path);

        if ($measurement === null) {
            return $unresolved('neither the container header nor a packet count yielded a duration', $path);
        }

        return [
            'log_id' => $run->id,
            'processing_id' => $run->processing_id,
            'disposition' => self::DispositionMeasurable,
            'archive_path' => $path,
            'measured_duration' => $measurement['duration'],
            'measured_by' => $measurement['method'],
            'reason' => null,
        ];
    }

    /**
     * The recording's length, and what had to be read to learn it.
     *
     * The header is asked first because it costs nothing. It answers `N/A` for
     * every one of these files: a YouTube live capture writes no duration into
     * the WebM at all, in the format or the streams, which is precisely why
     * these runs recorded no duration when they were processed. The empty column
     * is the symptom of that, not of a lost measurement.
     *
     * Counting video packets recovers it for about a second per file — the same
     * measurement curation has used on this corpus since it was drafted, and one
     * already shown to reproduce the operator's hand-measured durations.
     *
     * @return array{duration: float, method: 'header'|'packet_count'}|null
     */
    private function measure(string $path): ?array
    {
        try {
            return ['duration' => $this->durationProbe->durationOf($path), 'method' => 'header'];
        } catch (Throwable) {
            // A container that declares nothing is the normal case here.
        }

        $counted = $this->packetCountedDuration->seconds($path);

        return $counted === null ? null : ['duration' => $counted, 'method' => 'packet_count'];
    }

    /**
     * Why this archive copy is not the recording the run processed, or null.
     *
     * Size is the manifest's own approved identity record and separates the
     * captures of a service far more cheaply than a hash of a two-gigabyte file
     * on a drive that has detached mid-pass before. It is a weaker proof, which
     * is why it is stated on every row rather than assumed, and why
     * `--verify-hash` exists for a run whose measurement will decide a
     * publishable boundary.
     */
    private function identityFailure(MediaProcessingLog $run, string $path, bool $verifyHash): ?string
    {
        $import = ($run->processing_metadata?->toArray() ?? [])['historic_import'] ?? [];
        $source = is_array($import) && is_array($import['sources'][0] ?? null) ? $import['sources'][0] : [];
        $expectedSize = is_numeric($source['size'] ?? null) ? (int) $source['size'] : null;

        if ($expectedSize === null) {
            return 'the approved manifest records no size for this source, so the copy cannot be identified';
        }

        $observedSize = filesize($path);

        if ($observedSize !== $expectedSize) {
            return sprintf(
                'the archive copy is %d bytes and the manifest approved %d, so it is a different recording',
                $observedSize === false ? -1 : $observedSize,
                $expectedSize,
            );
        }

        if (! $verifyHash) {
            return null;
        }

        $expectedHash = $run->recordedSourceFileHash();

        if ($expectedHash === null) {
            return 'no recorded hash exists to verify this copy against';
        }

        return hash_file('sha256', $path) === $expectedHash
            ? null
            : 'the archive copy does not hash to the recording this run processed';
    }
}
