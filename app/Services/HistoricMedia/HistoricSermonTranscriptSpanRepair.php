<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\FlagSermonTextPredatesEvidence;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\TranscriptStorageService;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Regenerates a banked sermon transcript from the spans its media was cut from.
 *
 * Runs completed before the span fix sliced the full-service transcript between
 * the extraction plan's *outer* bounds, so a concatenated run banked the hymn or
 * notices between the preached reading and the sermon as sermon text. The
 * evidence needed to correct that — the run's own full-service transcript — is
 * retained, so this needs no re-transcription and no re-extraction.
 *
 * The repair writes in place, on the disk the current transcript was found on.
 * {@see TranscriptStorageService::storeTranscript()} would write to the
 * *configured* transcript disk instead, which for a promoted historic sermon is
 * the staging batch rather than the quarantine copy the sermon actually points
 * at, and would leave two transcripts and a stale record.
 *
 * Deletion trigger: delete once every affected historic run is repaired and the
 * Phase 8 closeout retention window has expired.
 */
class HistoricSermonTranscriptSpanRepair
{
    public const DISPOSITION_REPAIRABLE = 'repairable';

    public const DISPOSITION_ALREADY_REPAIRED = 'already repaired';

    public const DISPOSITION_UNAFFECTED = 'unaffected';

    public const DISPOSITION_UNRESOLVED = 'unresolved';

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly FlagSermonTextPredatesEvidence $sermonTextPredatesEvidence,
        private readonly ServiceTranscriptReader $serviceTranscripts,
        private readonly TranscriptStorageService $transcriptStorage,
    ) {}

    /**
     * Classify each run without writing anything.
     *
     * A sermon transcript is derived from the full-service transcript and the
     * run's extraction spans, so it goes stale whenever either changes. This
     * repair is one such trigger; {@see HistoricTranscriptRecoveryReplay} is
     * another, and asks with `$requireConcatenatedPlan: false` because the
     * transcript it changed is the service one, which stales a single-span
     * sermon exactly as readily as a concatenated one. Which runs are worth
     * asking about is the caller's question; how the answer is derived is not,
     * and must not become a second copy of it.
     *
     * @param  iterable<int, MediaProcessingLog>  $runs
     * @return list<SermonTranscriptSpanRepairEntry>
     */
    public function inspect(iterable $runs, bool $requireConcatenatedPlan = true): array
    {
        $entries = [];

        foreach ($runs as $run) {
            $entries[] = $this->inspectRun($run, $requireConcatenatedPlan);
        }

        return $entries;
    }

    /**
     * Write the repaired transcripts, skipping every other disposition.
     *
     * Each run is written under its own staging context and stamped with what
     * changed, so a re-run is a no-op and an interrupted pass resumes cleanly.
     *
     * @param  list<SermonTranscriptSpanRepairEntry>  $entries
     * @return array{repaired: int, reconciled: int, failed: int, failures: list<string>}
     */
    public function apply(array $entries): array
    {
        $repaired = 0;
        $reconciled = 0;
        $failed = 0;
        $failures = [];

        foreach ($entries as $entry) {
            if ($entry->disposition === self::DISPOSITION_ALREADY_REPAIRED) {
                $reconciled += $this->reconcile($entry) ? 1 : 0;

                continue;
            }

            if (! $entry->isRepairable()) {
                continue;
            }

            $run = MediaProcessingLog::query()->find($entry->logId);

            if (! $run instanceof MediaProcessingLog) {
                $failed++;
                $failures[] = $entry->processingId.': the run no longer exists.';

                continue;
            }

            try {
                $this->withRunContext($run, function () use ($run, $entry): void {
                    $this->write($run, $entry);
                });
                $repaired++;
            } catch (Throwable $exception) {
                $failed++;
                $failures[] = $entry->processingId.': '.$exception->getMessage();

                Log::error('Sermon transcript span repair failed', [
                    'processing_id' => $entry->processingId,
                    'sermon_id' => $entry->sermonId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['repaired' => $repaired, 'reconciled' => $reconciled, 'failed' => $failed, 'failures' => $failures];
    }

    /**
     * Stamp a run whose saved text already *is* the current derivation.
     *
     * `already repaired` is not "nothing to do": it means the saved text is
     * identical to a fresh slice of the transcript the run holds now, which is
     * positive evidence that the derivation is current. Without this the stamp
     * never catches up and the stale-derivation hold stands forever on text
     * that is demonstrably right — five runs were in exactly that state after
     * the first pass. The evidence comes from the same read the rewrite path
     * uses, so this reconciles a record to a fact rather than clearing a hold
     * to tidy the queue.
     */
    private function reconcile(SermonTranscriptSpanRepairEntry $entry): bool
    {
        $run = MediaProcessingLog::query()->find($entry->logId);

        if (! $run instanceof MediaProcessingLog || ! $run->sermonDerivationIsOwed()) {
            return false;
        }

        $this->stampDerivation($run, $entry);

        return true;
    }

    private function inspectRun(MediaProcessingLog $run, bool $requireConcatenatedPlan): SermonTranscriptSpanRepairEntry
    {
        $spans = $run->recordedSermonExtractionSpans();

        $entry = new SermonTranscriptSpanRepairEntry(
            processingId: (string) $run->processing_id,
            logId: (int) $run->id,
            sermonId: $run->sermon_id,
            spanCount: $spans === null ? 0 : count($spans),
            disposition: self::DISPOSITION_UNAFFECTED,
        );

        if ($run->status !== ProcessingStatus::Completed) {
            return $entry->with(self::DISPOSITION_UNAFFECTED, 'not a completed run');
        }

        if ($spans === null || $spans === []) {
            return $entry->with(self::DISPOSITION_UNAFFECTED, 'no recorded extraction plan');
        }

        if ($requireConcatenatedPlan && count($spans) < 2) {
            /**
             * A single-span plan sets the run bounds to that one span, so the
             * old slicing selected exactly the same cues. Nothing for *this*
             * defect to repair — a caller whose service transcript has since
             * changed asks without this gate.
             */
            return $entry->with(self::DISPOSITION_UNAFFECTED, 'no concatenated extraction plan');
        }

        $sermon = $run->sermon;

        if (! $sermon instanceof Sermon) {
            return $entry->with(self::DISPOSITION_UNRESOLVED, 'the run has no sermon');
        }

        try {
            return $this->withRunContext($run, function () use ($run, $sermon, $spans, $entry): SermonTranscriptSpanRepairEntry {
                $transcriptPath = (string) $sermon->transcript_file_path;
                $disk = $this->transcriptStorage->locateTranscriptDisk($transcriptPath, $sermon->asset_disk);

                if ($disk === null) {
                    return $entry->with(self::DISPOSITION_UNRESOLVED, 'the saved transcript is unavailable');
                }

                $serviceTranscript = $this->serviceTranscripts->tryRead($run);

                if ($serviceTranscript === null) {
                    return $entry->with(self::DISPOSITION_UNRESOLVED, 'the full-service transcript is unavailable');
                }

                $repairedText = trim($serviceTranscript->sliceTextForSpans($spans));

                if ($repairedText === '') {
                    return $entry->with(self::DISPOSITION_UNRESOLVED, 'the recorded spans select no speech');
                }

                $currentText = (string) Storage::disk($disk)->get($transcriptPath);

                return new SermonTranscriptSpanRepairEntry(
                    processingId: $entry->processingId,
                    logId: $entry->logId,
                    sermonId: $entry->sermonId,
                    spanCount: $entry->spanCount,
                    disposition: trim($currentText) === $repairedText
                        ? self::DISPOSITION_ALREADY_REPAIRED
                        : self::DISPOSITION_REPAIRABLE,
                    disk: $disk,
                    path: $transcriptPath,
                    currentLength: strlen($currentText),
                    repairedLength: strlen($repairedText),
                    repairedText: $repairedText,
                    serviceTranscriptHash: MediaProcessingLog::hashServiceTranscriptContent($serviceTranscript),
                );
            });
        } catch (Throwable $exception) {
            /**
             * A run whose batch will not open — a staging identity that no
             * longer matches this process, an unreachable volume — is one
             * unresolved row, not a dead pass. Naming it keeps the rest of the
             * selection inspectable.
             */
            return $entry->with(self::DISPOSITION_UNRESOLVED, 'the staging batch could not be opened: '.$exception->getMessage());
        }
    }

    private function write(MediaProcessingLog $run, SermonTranscriptSpanRepairEntry $entry): void
    {
        $disk = (string) $entry->disk;
        $path = (string) $entry->path;

        if (! Storage::disk($disk)->put($path, (string) $entry->repairedText)) {
            throw new \RuntimeException('Failed to write the repaired transcript.');
        }

        $spans = $run->recordedSermonExtractionSpans();

        /**
         * Through the model's safe writer, not a read-modify-write on the
         * instance held across the write above: `processing_metadata` is one
         * JSON column many writers share, and saving a stale snapshot drops
         * their keys. {@see MediaProcessingLog::writeProcessingMetadata()}.
         */
        $run->writeProcessingMetadata(static function (array $metadata) use ($disk, $path, $spans, $entry): array {
            $metadata['transcript_span_repair'] = [
                'repaired_at' => now()->toIso8601String(),
                'disk' => $disk,
                'path' => $path,
                'spans' => $spans,
                'previous_length' => $entry->currentLength,
                'repaired_length' => $entry->repairedLength,
            ];

            return $metadata;
        });

        /**
         * The analysis banked for this sermon was derived from the text just
         * replaced. Recording what the transcript now holds is what lets a
         * later invocation — or a resumed pass after a crash between here and
         * the dispatch loop — see that analysis is still owed. Text equality
         * cannot express that: once repaired, the row simply looks finished.
         */
        $run->recordTranscriptContent(
            MediaProcessingLog::hashTranscriptContent((string) $entry->repairedText),
        );

        /**
         * Stamp what this text was sliced from, then re-ask the hold that
         * measures the two against each other.
         *
         * Re-slicing is only half a repair. `sermonDerivationIsOwed()` compares
         * `sermon_derived_from_service_transcript` against the run's current
         * `service_transcript_content` hash, so a repair that rewrote the text
         * and stamped neither leaves the sermon current and the run still
         * claiming the derivation is owed — the reader sees correct text behind
         * a hold that never lifts. This job is the writer that re-slices, so per
         * {@see FlagSermonTextPredatesEvidence} it is the only event allowed to
         * withdraw that hold.
         *
         * The hash is absent only for a verdict built before this field existed,
         * in which case the stamp is skipped rather than guessed.
         */
        $this->stampDerivation($run, $entry);

        Log::info('Sermon transcript repaired to its extraction spans', [
            'processing_id' => $run->processing_id,
            'sermon_id' => $run->sermon_id,
            'disk' => $disk,
            'previous_length' => $entry->currentLength,
            'repaired_length' => $entry->repairedLength,
        ]);
    }

    /**
     * Record what the saved text was sliced from, and re-ask the hold.
     *
     * The hash is absent only for a verdict built before this field existed, in
     * which case the stamp is skipped rather than guessed.
     */
    private function stampDerivation(MediaProcessingLog $run, SermonTranscriptSpanRepairEntry $entry): void
    {
        if (! is_string($entry->serviceTranscriptHash) || $entry->serviceTranscriptHash === '') {
            return;
        }

        $run->recordSermonDerivedFrom($entry->serviceTranscriptHash);
        ($this->sermonTextPredatesEvidence)($run->refresh());
    }

    /**
     * Run a callback under the run's own staging batch.
     *
     * A historic run's artifact keys resolve against the batch root the staging
     * guard installs, so they only resolve while that context is open — see
     * {@see MediaProcessingLog::historicStagingContext()}. Reading without it
     * reports every surviving artifact as lost.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withRunContext(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        if ($context === null) {
            return $callback();
        }

        return $this->stagingContexts->within($context, $callback);
    }
}
