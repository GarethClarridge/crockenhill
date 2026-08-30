<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Deletion trigger: delete after the historic-video operation's stranded
 * processing tail has been recovered, verified and retained in its closeout evidence.
 */
class RecoverHistoricProcessingTailCommand extends Command
{
    protected $signature = 'historic-import:recover-processing-tail
                            {processing_id : Exact processing ID of the stale historic run}
                            {--operation= : Exact immutable historic operation that owns the run}';

    protected $description = 'Recover only the promotion and cleanup tail of a stale historic processing run';

    public function handle(ProcessingRunOrchestrator $orchestrator): int
    {
        try {
            $operation = $this->operation();
            $processingId = $this->processingId();
            $processingLog = MediaProcessingLog::query()
                ->where('processing_id', $processingId)
                ->first();

            if (! $processingLog instanceof MediaProcessingLog) {
                throw new RuntimeException("Processing run {$processingId} does not exist.");
            }

            $result = $orchestrator->recoverHistoricTail($processingLog, $operation);

            if (! $result->success) {
                throw new RuntimeException($result->message);
            }

            $this->info("{$result->message} Processing ID: {$processingId}.");

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

        $operationId = trim($operationId);
        $operation = HistoricImportOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException("Historic import operation {$operationId} does not exist.");
        }

        return $operation;
    }

    private function processingId(): string
    {
        $processingId = trim((string) $this->argument('processing_id'));

        if ($processingId === '') {
            throw new RuntimeException('An exact processing ID is required.');
        }

        return $processingId;
    }
}
