<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportArtifactKind;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricImportOperationalCloseoutEvidence;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic import acceptance and rollback-retention windows
 * have expired and the closeout index has moved to long-term custody.
 */
class VerifyHistoricImportOperationalCloseoutCommand extends Command
{
    protected $signature = 'historic-import:verify-operational-closeout
        {operation : Immutable historic import operation id}
        {evidence : Signed smoke, monitoring and runtime-recovery JSON}';

    protected $description = 'Verify and retain operational evidence required before releasing the import freeze';

    public function handle(
        HistoricImportOperationalCloseoutEvidence $closeout,
        HistoricImportArtifactWriter $artifacts,
        HistoricImportJournal $journal,
    ): int {
        try {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', (string) $this->argument('operation'))
                ->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('Historic import operational closeout operation does not exist.');
            }

            $evidence = $this->read((string) $this->argument('evidence'));
            $verified = $closeout->verify(
                $operation,
                $evidence,
                (string) config('media-processing.historic_import.evidence_signing_key'),
            );
            $artifact = $artifacts->writeJson(
                operation: $operation,
                artifactKey: 'operational-closeout-readiness-v2',
                kind: HistoricImportArtifactKind::AcceptanceReport,
                relativePath: 'closeout/operational-readiness-v2.json.enc',
                payload: $verified,
                redact: false,
            );
            $journal->append($operation, 'operational_closeout_verified', [
                'artifact_key' => $artifact->artifact_key,
                'artifact_sha256' => $artifact->sha256,
                'audit_report_sha256' => $verified['audit_report_sha256'],
            ]);

            $this->info('Historic import operational closeout evidence is exact and retained.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Historic import operational closeout evidence is missing.');
        }

        $evidence = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($evidence)) {
            throw new RuntimeException('Historic import operational closeout evidence must be a JSON object.');
        }

        return $evidence;
    }
}
