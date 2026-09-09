<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Restore a completed run's source recording from an archive copy, proving it is
 * the same file rather than merely the same service.
 *
 * `CleanupTemporaryFiles` deletes `source_file_path` when a run finishes, so a
 * finished run usually has nothing left to cut from. The archive drive often
 * still holds the recording — but "a recording of that service" is not good
 * enough, and the reason is not obvious.
 *
 * **The banked transcript is timed against the original recording.** Structure
 * detection reads that transcript to produce section times, and extraction then
 * cuts the media at those times. A replacement that is the same service from a
 * different capture — even a few seconds' different start — gives every section
 * a silently wrong cut, and nothing in the output reveals it. So this refuses
 * anything but a byte-identical file: the candidate must hash to the `file_hash`
 * the run recorded, and the copy is re-hashed after writing.
 *
 * That makes this a *restore*, not a substitution. A run with no recorded hash
 * cannot be verified this way and is refused rather than copied on faith; for
 * those the RMS log is the evidence to reach for next, since it establishes
 * timeline alignment without byte-identity.
 *
 * The hash comes from {@see MediaProcessingLog::recordedSourceFileHash()}, which
 * reads the `file_hash` column or, for a single-part unconcatenated import, the
 * sha256 the approved manifest recorded. Reading only the column refused every
 * operation-4 run — all 416 of them — while the evidence sat in their metadata.
 *
 * One run and one file per invocation, on purpose. This is a deliberate act on a
 * terminal run, and naming exactly which file goes where is most of the safety.
 *
 * Delete once the historic import's source-retention decision is settled.
 */
class RestageHistoricSourceCommand extends Command
{
    protected $signature = 'historic-import:restage-source
        {run : Media processing log ID}
        {source : Absolute path to the archive copy of its source recording}
        {--execute : Write the file; without this option the command only verifies}';

    protected $description = 'Restore a historic run\'s source recording from a byte-identical archive copy';

    public function handle(HistoricStagingContextRegistry $stagingContexts): int
    {
        try {
            $log = MediaProcessingLog::find((int) $this->argument('run'));

            if (! $log instanceof MediaProcessingLog) {
                throw new RuntimeException('That processing run does not exist.');
            }

            $candidate = (string) $this->argument('source');

            if (! is_file($candidate) || ! is_readable($candidate)) {
                throw new RuntimeException("The archive copy {$candidate} is not a readable file.");
            }

            $target = $log->source_file_path;

            if (! is_string($target) || $target === '') {
                throw new RuntimeException('This run records no source path to restore to.');
            }

            // Asked before hashing rather than inside the restore below.
            // Hashing a 2 GB archive copy takes minutes on this drive, and a
            // sweep resumed after an interruption — a detach mid-run is the
            // documented failure here — would otherwise pay that for every file
            // it had already restored before reaching the ones it had not.
            $alreadyPresent = $this->withinContext($stagingContexts, $log, static fn (): bool => Storage::disk(
                (string) config('media-processing.storage.temp_disk', 'local')
            )->exists($target));

            if ($alreadyPresent) {
                $this->warn("The source is already present at {$target}; nothing to restore.");

                return self::SUCCESS;
            }

            $expected = $log->recordedSourceFileHash();

            if ($expected === null) {
                throw new RuntimeException(
                    'This run recorded no usable file hash, so a candidate cannot be proved to be the same recording. '
                    .'A multi-part import is concatenated at staging, so no single archive file can match it. '
                    .'Compare the candidate against the run\'s banked RMS log instead.'
                );
            }

            $this->line('Hashing the archive copy…');
            $observed = hash_file('sha256', $candidate);

            $source = is_string($log->file_hash) && $log->file_hash !== '' ? 'file_hash column' : 'approved manifest';
            $this->table(['', 'sha256'], [["recorded ({$source})", $expected], ['candidate', (string) $observed]]);

            if ($observed !== $expected) {
                throw new RuntimeException(
                    'The archive copy is not the recording this run processed. Its sections are timed against the '
                    .'original, so cutting them from this file would misplace every one of them.'
                );
            }

            $this->info('The archive copy is byte-identical to the recording this run processed.');

            $restore = function () use ($log, $candidate, $target, $expected): int {
                $disk = (string) config('media-processing.storage.temp_disk', 'local');

                if (! (bool) $this->option('execute')) {
                    $this->warn('VERIFY ONLY: nothing was written.');
                    $this->line("Would restore to {$disk}:{$target}");

                    return self::SUCCESS;
                }

                $stream = fopen($candidate, 'rb');

                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to open the archive copy for reading.');
                }

                try {
                    $this->line("Restoring to {$disk}:{$target}…");

                    if (Storage::disk($disk)->writeStream($target, $stream) === false) {
                        throw new RuntimeException('Unable to write the restored source.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                // Re-hashed after writing: a truncated copy is exactly the failure
                // this command exists to prevent, and it would otherwise look
                // like a success.
                $written = hash_file('sha256', Storage::disk($disk)->path($target));

                if ($written !== $expected) {
                    Storage::disk($disk)->delete($target);

                    throw new RuntimeException('The restored copy does not hash correctly and has been removed.');
                }

                Log::info('Restored a historic run source from an archive copy', [
                    'processing_id' => $log->processing_id,
                    'path' => $target,
                    'sha256' => $written,
                ]);

                $this->info('Restored and verified.');

                return self::SUCCESS;
            };

            return $this->withinContext($stagingContexts, $log, $restore);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Run the callback inside the run's staging context, where its recorded
     * source path resolves to the batch root it was staged under.
     *
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    private function withinContext(HistoricStagingContextRegistry $stagingContexts, MediaProcessingLog $log, \Closure $callback): mixed
    {
        $context = $log->historicStagingContext();

        return $context === null ? $callback() : $stagingContexts->within($context, $callback);
    }
}
