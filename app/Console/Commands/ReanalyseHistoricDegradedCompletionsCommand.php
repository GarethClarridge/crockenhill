<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic import's degraded-completion backlog is empty and
 * the closeout retention window has expired.
 *
 * Unlike {@see ReplayHistoricVideoPilotAnalysisCommand}, this calls the analysis
 * provider: a degraded completion banked no real analysis to replay, only a
 * filename-derived title and empty fields (see `createFallbackAnalysis()` on
 * {@see ProcessTranscriptWithAI}). Re-dispatching it plainly is enough — its own
 * success path now fills every blank field and clears `is_degraded_completion`,
 * and its own retry/backoff and flex-tier fallback apply exactly as they would
 * for the pass that first ran it.
 */
class ReanalyseHistoricDegradedCompletionsCommand extends Command
{
    protected $signature = 'historic-import:reanalyse-degraded-completions
                            {--operation= : Historic operation that owns every selected run}
                            {--processing-id=* : Exact degraded completed processing ID to re-analyse; repeat for every run}';

    protected $description = 'Re-dispatch AI analysis for completed historic runs that banked a degraded (fallback) completion';

    public function handle(HistoricProcessingThroughput $throughput): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();

            $runs = MediaProcessingLog::query()
                ->where('historic_import_operation_id', $operation->id)
                ->whereIn('processing_id', $processingIds)
                ->orderBy('id')
                ->get();

            if ($runs->count() !== count($processingIds)) {
                throw new RuntimeException('Every selected processing ID must belong to the named historic operation.');
            }

            $ineligible = $runs->filter(
                fn (MediaProcessingLog $run): bool => $run->status !== ProcessingStatus::Completed || ! $run->is_degraded_completion,
            );

            if ($ineligible->isNotEmpty()) {
                throw new RuntimeException(
                    'Every selected run must be a completed degraded completion. Not eligible: '
                    .$ineligible->pluck('processing_id')->implode(', ')
                );
            }

            $queue = $throughput->queueForClass(ProcessTranscriptWithAI::class);

            $rows = $runs->map(function (MediaProcessingLog $run) use ($queue): array {
                ProcessTranscriptWithAI::dispatch($run)->onQueue($queue);

                return [$run->processing_id, $run->sermon_id, $queue];
            })->all();

            $this->table(['Processing ID', 'Sermon ID', 'Queue'], $rows);
            $this->info(sprintf(
                '%d re-analysis job(s) dispatched. Check historic-import:video-pass-status once the queue drains.',
                $runs->count(),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function operation(): HistoricImportOperation
    {
        $operationId = $this->option('operation');

        if (! is_string($operationId) || trim($operationId) === '') {
            throw new RuntimeException('The owning historic operation is required.');
        }

        $operation = HistoricImportOperation::query()
            ->where('operation_id', trim($operationId))
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException('The named historic operation does not exist.');
        }

        return $operation;
    }

    /** @return list<string> */
    private function processingIds(): array
    {
        $processingIds = array_values(array_unique(array_filter(
            $this->option('processing-id'),
            static fn (mixed $processingId): bool => is_string($processingId) && trim($processingId) !== '',
        )));

        if ($processingIds === []) {
            throw new RuntimeException('At least one exact degraded completed --processing-id is required.');
        }

        return array_map(trim(...), $processingIds);
    }
}
