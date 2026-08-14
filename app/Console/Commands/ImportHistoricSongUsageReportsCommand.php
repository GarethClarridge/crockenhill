<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportArtifactKind;
use App\Enums\HistoricSongUsageRowOutcome;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Song\HistoricSongUsageCloseout;
use App\Services\Song\HistoricSongUsageReportImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * One-shot import for the 2026-08-09 hymn reconciliation workbook.
 *
 * F61 moved this lane inside the historic import operation controls. It used to write
 * immediately on `--import`, with no production guard, no operation binding, no approved
 * workbook digest and no contribution to closeout — so the plans claimed the ordinary backup,
 * freeze and approval gates applied to it while the code enforced none of them. (That list
 * also named a witness gate; D10 removed it — this is a one-person project.)
 *
 * Deletion trigger: remove this command and its workbook-reader/importer services after the
 * production import has been reconciled, backed up and signed off in the historic archive plan.
 */
#[Signature('service-tracking:import-historic-song-usage-reports
    {--path=storage/scratch/outputs/hymn-reconciliation-2026-08-09/hymn-service-song-reconciliation.xlsx : Reconciliation workbook path}
    {--operation= : Immutable historic import operation id this run belongs to (required with --import)}
    {--expect-rows= : Approved dry-run row count; the run refuses before writes if it differs}
    {--expect-resolved= : Approved dry-run catalogue-resolved count}
    {--expect-unresolved= : Approved dry-run unresolved count}
    {--import : Persist reports; without this option the command is read-only}
    {--resolve-catalogue-matches : Authorise updating rows the catalogue can now resolve}
    {--link-canonical-occurrences : Authorise linking rows a canonical service item now proves}')]
#[Description('Import date-only historic song usage as evidence without inventing morning/evening services')]
class ImportHistoricSongUsageReportsCommand extends Command
{
    /**
     * The manifest binding an operation must carry for this lane. The workbook is this lane's
     * whole source, so its digest is the operation's source-kind binding.
     */
    private const SourceKind = 'historic_song_usage';

    public function handle(
        HistoricSongUsageReportImporter $importer,
        HistoricImportProductionGuard $productionGuard,
        HistoricImportArtifactWriter $artifacts,
        HistoricImportJournal $journal,
    ): int {
        $path = (string) $this->option('path');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        $persist = (bool) $this->option('import');

        try {
            $operation = $this->resolveOperation($persist);

            if ($persist) {
                $refusal = $productionGuard->refusalFor(
                    'service-tracking:import-historic-song-usage-reports',
                    $operation?->operation_id,
                );

                if ($refusal !== null) {
                    $this->error($refusal);

                    return self::FAILURE;
                }
            }

            $metrics = $importer->import(
                path: $path,
                persist: $persist,
                resolveCatalogueMatches: (bool) $this->option('resolve-catalogue-matches'),
                linkCanonicalOccurrences: (bool) $this->option('link-canonical-occurrences'),
                operation: $operation,
                approvedWorkbookSha256: $operation?->manifest_hashes[self::SourceKind] ?? null,
                expectedCounts: $this->expectedCounts(),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($metrics['dry_run']) {
            $this->warn('Dry run: no rows were written.');
        }

        if ($operation instanceof HistoricImportOperation && ! $metrics['dry_run']) {
            $this->recordImport($operation, $metrics, $artifacts, $journal);
        }

        $this->table(['Row outcome', 'Count'], $this->outcomeRows($metrics['outcomes']));

        $this->table(['Metric', 'Count'], [
            ['Rows read', (string) $metrics['rows_read']],
            ['Stored resolved', (string) $metrics['resolved']],
            ['Stored unresolved', (string) $metrics['unresolved']],
            ['Stored linked to a canonical item', (string) $metrics['linked']],
        ]);

        $this->line("Workbook digest: {$metrics['workbook_sha256']}");
        $this->line("Row membership digest: {$metrics['membership_sha256']}");

        return self::SUCCESS;
    }

    /**
     * A persisting run is owned by an operation or it does not run.
     *
     * Read-only runs deliberately need none: revalidating the workbook before an operation
     * exists is how the approved contract gets measured in the first place.
     */
    private function resolveOperation(bool $persist): ?HistoricImportOperation
    {
        $operationId = $this->option('operation');
        $operationId = is_string($operationId) && trim($operationId) !== '' ? trim($operationId) : null;

        if ($operationId === null) {
            if ($persist) {
                throw new RuntimeException(
                    'A persisting historic hymn import must name its immutable operation with --operation.',
                );
            }

            return null;
        }

        $operation = HistoricImportOperation::query()->where('operation_id', $operationId)->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException("Historic import operation {$operationId} does not exist.");
        }

        if (! array_key_exists(self::SourceKind, $operation->manifest_hashes)) {
            throw new RuntimeException(
                "Operation {$operationId} carries no '".self::SourceKind."' manifest binding, so it cannot own this workbook.",
            );
        }

        return $operation;
    }

    /**
     * The recorded 1,941 / 1,867 / 74 dry-run contract, when the operator supplies it.
     *
     * All three or none: a partial contract would let an unstated count drift silently past a
     * gate whose whole purpose is that it cannot.
     *
     * @return array{rows:int,resolved:int,unresolved:int}|null
     */
    private function expectedCounts(): ?array
    {
        $options = [
            'rows' => $this->option('expect-rows'),
            'resolved' => $this->option('expect-resolved'),
            'unresolved' => $this->option('expect-unresolved'),
        ];
        $supplied = array_filter($options, static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if ($supplied === []) {
            return null;
        }

        if (count($supplied) !== 3) {
            throw new RuntimeException(
                'The approved hymn contract needs --expect-rows, --expect-resolved and --expect-unresolved together.',
            );
        }

        $rows = $this->contractValue($options['rows'], 'rows');
        $resolved = $this->contractValue($options['resolved'], 'resolved');
        $unresolved = $this->contractValue($options['unresolved'], 'unresolved');

        if ($resolved + $unresolved !== $rows) {
            throw new RuntimeException('The approved hymn contract resolved and unresolved counts do not sum to its rows.');
        }

        return ['rows' => $rows, 'resolved' => $resolved, 'unresolved' => $unresolved];
    }

    private function contractValue(mixed $value, string $name): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($parsed) || $parsed < 0) {
            throw new RuntimeException("The approved hymn contract value for {$name} must be a non-negative integer.");
        }

        return $parsed;
    }

    /**
     * The lane's closeout evidence: an operation-owned artifact naming every row it wrote.
     *
     * Closeout re-derives the same digest from the persisted rows, so this is what makes
     * "all the approved rows are still there" checkable rather than asserted.
     *
     * @param  array{rows_read:int,resolved:int,unresolved:int,linked:int,workbook_sha256:string,membership:list<array{fingerprint:string,outcome:string}>,membership_sha256:string,outcomes:array<string,int>}  $metrics
     */
    private function recordImport(
        HistoricImportOperation $operation,
        array $metrics,
        HistoricImportArtifactWriter $artifacts,
        HistoricImportJournal $journal,
    ): void {
        /**
         * Historic import artifacts never overwrite, so each run reports under its own ordinal.
         * Closeout reads the latest, which is what makes an authorised catalogue-resolution
         * rerun the current statement of the lane rather than a second competing one.
         */
        $ordinal = $operation->artifacts()
            ->where('artifact_key', 'like', HistoricSongUsageCloseout::ArtifactKey.'-%')
            ->count() + 1;
        $artifact = $artifacts->writeJson(
            operation: $operation,
            artifactKey: sprintf('%s-%03d', HistoricSongUsageCloseout::ArtifactKey, $ordinal),
            kind: HistoricImportArtifactKind::AcceptanceReport,
            relativePath: sprintf('song-usage/import-%03d.json.enc', $ordinal),
            payload: [
                'format' => 'crockenhill-historic-song-usage-import',
                'version' => 1,
                'operation_id' => $operation->operation_id,
                'target_fingerprint' => $operation->target_fingerprint,
                'workbook_sha256' => $metrics['workbook_sha256'],
                'rows_read' => $metrics['rows_read'],
                'stored_resolved' => $metrics['resolved'],
                'stored_unresolved' => $metrics['unresolved'],
                'stored_linked' => $metrics['linked'],
                'outcomes' => $metrics['outcomes'],
                'membership' => $metrics['membership'],
                'membership_sha256' => $metrics['membership_sha256'],
            ],
            redact: false,
        );

        $journal->append($operation, 'song_usage_imported', [
            'workbook_sha256' => $metrics['workbook_sha256'],
            'rows_read' => $metrics['rows_read'],
            'membership_sha256' => $metrics['membership_sha256'],
            'artifact_key' => $artifact->artifact_key,
            'artifact_sha256' => $artifact->sha256,
        ]);
    }

    /**
     * @param  array<string,int>  $outcomes
     * @return list<array{0:string,1:string}>
     */
    private function outcomeRows(array $outcomes): array
    {
        $rows = [];

        foreach ($outcomes as $value => $count) {
            $rows[] = [HistoricSongUsageRowOutcome::from($value)->label(), (string) $count];
        }

        return $rows;
    }
}
