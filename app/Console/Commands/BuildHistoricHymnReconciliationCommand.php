<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Song\HistoricHymnReconciliation;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Regenerate the historic hymn reconciliation from its original workbooks.
 *
 * This is F60's retained, auditable generation procedure. The 2026-08-09 workbook it
 * replaces was produced by a script that was never committed, so its figures could not
 * be reproduced or re-derived after the corpus moved underneath them.
 *
 * Read-only by construction: it opens workbooks, queries the catalogue and the service
 * corpus, and writes one artifact. It has no `--import`, and adding one here would be
 * the wrong place — the date-only lane already has an operation-guarded importer, and
 * the service-known lane has no approved disposition at all yet.
 *
 * Point `--connection` at the rehearsal database to reconcile against staged evidence
 * rather than against whatever the working development database happens to hold. The
 * artifact records which database answered, because a reconciliation is only meaningful
 * against a named corpus.
 */
class BuildHistoricHymnReconciliationCommand extends Command
{
    protected $signature = 'service-tracking:build-hymn-reconciliation
        {--source=* : Source hymn workbook path; repeat for each of the four snapshots}
        {--output= : Absolute path the reconciliation artifact is created at}
        {--connection= : Named database connection holding the corpus to reconcile against}
        {--accept-fuzzy : Accept fuzzy catalogue matches as resolutions instead of recording them for review}';

    protected $description = 'Regenerate the historic hymn reconciliation and its bindings from the original workbooks';

    public function handle(HistoricHymnReconciliation $reconciliation): int
    {
        try {
            $this->selectConnection();

            $artifact = $reconciliation->generate($this->sources(), (bool) $this->option('accept-fuzzy'));
            $path = $this->outputPath();

            $this->createOnce($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

            $this->report($artifact);
            $this->line("Artifact: {$path}");
            $this->line('Artifact sha256: '.CanonicalJson::hash($artifact));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * A reconciliation reads through the models, so the corpus is chosen by pointing the
     * default connection at it rather than by threading a connection name through every
     * query. Nothing here writes, so the switch cannot escape as a mutation.
     */
    private function selectConnection(): void
    {
        $connection = $this->option('connection');

        if (! is_string($connection) || trim($connection) === '') {
            return;
        }

        DB::setDefaultConnection(trim($connection));
    }

    /** @return list<string> */
    private function sources(): array
    {
        /** @var array<int, string|null> $sources */
        $sources = $this->option('source');
        $paths = array_values(array_filter(array_map(
            static fn (?string $source): string => trim((string) $source),
            $sources,
        )));

        if ($paths === []) {
            throw new RuntimeException('At least one --source workbook is required.');
        }

        return $paths;
    }

    private function outputPath(): string
    {
        $option = $this->option('output');

        if (! is_string($option) || ! str_starts_with($option, '/')) {
            throw new RuntimeException('A hymn reconciliation requires an absolute --output path.');
        }

        return $option;
    }

    /**
     * Created once at a new path.
     *
     * A reconciliation is evidence. Overwriting one in place would invalidate any
     * figure already quoted from it, so a second run has to name a new path.
     */
    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Refusing to overwrite an existing artifact at {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write the artifact at {$path}.");
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $artifact */
    private function report(array $artifact): void
    {
        /** @var array<string, mixed> $counts */
        $counts = $artifact['counts'];
        /** @var array<string, int> $byDisposition */
        $byDisposition = $counts['by_disposition'];
        /** @var array<string, int> $identities */
        $identities = $counts['identities_by_disposition'];
        /** @var array<string, mixed> $corpus */
        $corpus = $artifact['corpus'];

        $this->info("Statements: {$counts['statements_total']} ({$counts['service_known']} with a service, {$counts['date_only']} date-only)");
        $this->line("Catalogue: {$counts['catalogue_resolved']} resolved, {$counts['catalogue_unresolved']} unresolved ({$counts['catalogue_fuzzy_candidates']} of them holding a fuzzy candidate for review)");
        $this->line("Corpus: {$corpus['database']} — {$corpus['service_count']} services, {$corpus['song_item_count']} song items");

        $this->newLine();
        $this->table(
            ['Disposition', 'Statements', 'Identities'],
            array_map(
                static fn (string $disposition, int $count): array => [
                    $disposition,
                    $count,
                    $identities[$disposition] ?? '—',
                ],
                array_keys($byDisposition),
                array_values($byDisposition),
            ),
        );

        /** @var array<string, int> $anomalies */
        $anomalies = $counts['anomalies'];

        foreach ($anomalies as $kind => $count) {
            $this->warn("Source anomaly '{$kind}': {$count}");
        }

        if ($counts['duplicates'] > 0) {
            $this->warn("Duplicate statements needing a decision: {$counts['duplicates']}");
        }
    }
}
