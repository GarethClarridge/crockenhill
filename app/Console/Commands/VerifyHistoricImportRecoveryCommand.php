<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportArtifactKind;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricImportRecoveryEvidence;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic import rollback/acceptance window has expired and
 * the retained recovery artifact has been transferred to its long-term owner.
 */
class VerifyHistoricImportRecoveryCommand extends Command
{
    protected $signature = 'historic-import:verify-recovery
        {operation : Immutable historic import operation id}
        {evidence : Recovery and restore exercise JSON}';

    protected $description = 'Verify and retain exact database/object/staging/journal recovery evidence';

    public function handle(
        HistoricImportRecoveryEvidence $recovery,
        HistoricImportArtifactWriter $artifacts,
        HistoricImportJournal $journal,
    ): int {
        try {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', (string) $this->argument('operation'))
                ->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('Historic import recovery operation does not exist.');
            }
            $evidence = $this->read((string) $this->argument('evidence'));
            $verified = $recovery->verify($operation, $evidence);
            $artifact = $artifacts->writeJson(
                operation: $operation,
                artifactKey: 'recovery-rehearsal',
                kind: HistoricImportArtifactKind::Backup,
                relativePath: 'recovery/rehearsal.json.enc',
                payload: $verified,
                redact: false,
            );
            $journal->append($operation, 'recovery_rehearsal_verified', [
                'artifact_key' => $artifact->artifact_key,
                'artifact_sha256' => $artifact->sha256,
                'accepted_rpo_seconds' => $verified['accepted_rpo_seconds'],
                'accepted_rto_seconds' => $verified['accepted_rto_seconds'],
            ]);

            $this->info('Historic import recovery evidence is exact and retained by the operation.');

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
            throw new RuntimeException('Historic import recovery evidence is missing.');
        }

        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($value)) {
            throw new RuntimeException('Historic import recovery evidence must be a JSON object.');
        }

        return $value;
    }
}
