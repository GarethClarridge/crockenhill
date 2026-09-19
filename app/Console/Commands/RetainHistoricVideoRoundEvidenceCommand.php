<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricVideoRoundEvidenceCustody;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the signed Operation 4 cover and its evidence have reached
 * external long-term custody and historic-import IC8 closes.
 */
class RetainHistoricVideoRoundEvidenceCommand extends Command
{
    protected $signature = 'historic-import:retain-video-round-evidence
        {operation : Immutable historic import operation id}
        {directory : Directory containing the reviewed reports}
        {expectation : Bound historic-video manifest expectation JSON}';

    protected $description = 'Retain the exact historic-video round evidence under private operation custody';

    public function handle(HistoricVideoRoundEvidenceCustody $custody): int
    {
        try {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', (string) $this->argument('operation'))
                ->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('Historic video round custody operation does not exist.');
            }

            $files = $custody->retain(
                $operation,
                rtrim((string) $this->argument('directory'), DIRECTORY_SEPARATOR),
                (string) $this->argument('expectation'),
            );
            $this->info('Historic video round evidence retained privately: '.count($files).' files.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
