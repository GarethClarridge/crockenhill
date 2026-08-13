<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportArtifactKind;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricImportRecoveryArtifactResolver;
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
        {evidence : Signed recovery and restore exercise JSON}
        {--artifact=* : Repeatable artifact-id=verification-path mapping, one per declared artifact}';

    protected $description = 'Verify and retain signed, artifact-backed database/object/staging/journal recovery evidence';

    public function handle(
        HistoricImportRecoveryEvidence $recovery,
        HistoricImportRecoveryArtifactResolver $resolver,
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

            /** @var list<string> $options */
            $options = (array) $this->option('artifact');
            $verified = $recovery->verify(
                operation: $operation,
                evidence: $this->read((string) $this->argument('evidence')),
                artifactPaths: $resolver->parseMappings($options),
                signingKey: (string) config('media-processing.historic_import.evidence_signing_key'),
                keyId: (string) config('media-processing.historic_import.recovery_evidence_key_id'),
            );

            /**
             * HIR5 item 8: a new immutable key. The version 1 artifact stays
             * exactly where it is, because it is the superseded evidence a
             * reviewer needs in order to see what the repaired gate rejected.
             */
            $artifact = $artifacts->writeJson(
                operation: $operation,
                artifactKey: 'recovery-rehearsal-v2',
                kind: HistoricImportArtifactKind::Backup,
                relativePath: 'recovery/rehearsal-v2.json.enc',
                payload: $verified,
                redact: false,
            );
            $journal->append($operation, 'recovery_rehearsal_verified', [
                'artifact_key' => $artifact->artifact_key,
                'artifact_sha256' => $artifact->sha256,
                'accepted_rpo_seconds' => $verified['accepted_rpo_seconds'],
                'accepted_rto_seconds' => $verified['accepted_rto_seconds'],
                'verified_artifact_count' => count($verified['observed']['artifacts']),
                'restore_membership_sha256' => $verified['observed']['restores']['membership_sha256'],
            ]);

            $this->info('Historic import recovery evidence is signed, artifact-backed and retained by the operation.');
            $this->line('Verified artifacts: '.count($verified['observed']['artifacts']));

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
