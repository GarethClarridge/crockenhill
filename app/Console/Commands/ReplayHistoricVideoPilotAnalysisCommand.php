<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\Sermon\HistoricBankedSermonAnalysisReplay;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic-video pilot's banked analysis has been replayed,
 * verified, and the historic import's closeout retention window has expired.
 */
class ReplayHistoricVideoPilotAnalysisCommand extends Command
{
    protected $signature = 'historic-import:replay-video-pilot-analysis
                            {--operation= : Historic operation that owns every selected pilot run}
                            {--processing-id=* : Exact completed pilot processing ID to replay; repeat for every run}';

    protected $description = 'Repair selected historic-video pilot sermons from banked AI analysis without provider calls';

    public function handle(HistoricBankedSermonAnalysisReplay $replay): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $runs = MediaProcessingLog::query()
                ->with('sermon')
                ->where('historic_import_operation_id', $operation->id)
                ->whereIn('processing_id', $processingIds)
                ->where('status', ProcessingStatus::Completed)
                ->orderBy('id')
                ->get();

            if ($runs->count() !== count($processingIds)) {
                throw new RuntimeException('Every selected processing ID must be a completed run owned by the named historic operation.');
            }

            $rows = $runs->map(function (MediaProcessingLog $run) use ($replay): array {
                $result = $replay->replay($run);

                return [
                    $run->processing_id,
                    $result['changed'] === [] ? '(none)' : implode(', ', $result['changed']),
                    $result['refused'] === [] ? '(none)' : implode(', ', $result['refused']),
                ];
            })->all();

            $this->table(['Processing ID', 'Changed', 'Refused'], $rows);
            $this->info('Banked historic-video analysis replay completed without calling an analysis provider.');

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
            throw new RuntimeException('At least one exact completed pilot --processing-id is required.');
        }

        return array_map(trim(...), $processingIds);
    }
}
