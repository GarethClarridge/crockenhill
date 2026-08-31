<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricVideoSongCustodyRepair;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Repair historic song-video rows and report review-held section candidates.
 *
 * This is deliberately an exact-membership, dry-run-first command. The
 * operator names the owning operation and every processing run, reviews the
 * custody table, then opts into the private quarantine transition.
 *
 * Deletion trigger: Delete after IC8 closeout once the song custody repair and
 * its retained evidence are complete.
 */
class RepairHistoricVideoSongCustodyCommand extends Command
{
    protected $signature = 'historic-import:repair-video-song-custody
                            {--operation= : Exact immutable historic operation that owns the song runs}
                            {--processing-id=* : Exact completed historic processing ID; repeat for every run}
                            {--apply : Quarantine and promote the verified song assets (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Repair historic song-video asset custody into private quarantine';

    public function handle(HistoricVideoSongCustodyRepair $repair): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entries = $repair->inspect($operation, $processingIds);

            $this->table(
                ['Processing ID', 'Song videos', 'Held candidates', 'Staged bytes', 'Held bytes', 'Disposition'],
                array_map(static fn (array $entry): array => [
                    $entry['processing_id'],
                    (string) $entry['asset_count'],
                    (string) $entry['held_candidate_count'],
                    (string) $entry['staged_bytes'],
                    (string) $entry['held_bytes'],
                    $entry['disposition'],
                ], $entries),
            );

            if (! $apply) {
                $this->warn('DRY RUN: no database rows or assets were changed. Re-run with --apply --yes to repair this exact selection.');

                return self::SUCCESS;
            }

            $totals = $repair->apply($operation, $entries);

            if ($totals['repaired'] === 0) {
                $this->info('All selected song videos are already repaired; no changes were written.');
                $this->line("Held candidates retained for review: {$totals['held_candidates']}.");

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Promoted %d song video(s), %d asset(s) (%d bytes); reclaimed %d staging byte(s).',
                $totals['repaired'],
                $totals['assets_promoted'],
                $totals['promoted_bytes'],
                $totals['reclaimed_bytes'],
            ));
            $this->line("Held candidates retained for review: {$totals['held_candidates']}.");

            if ($totals['already_repaired'] > 0) {
                $this->line("Already repaired: {$totals['already_repaired']}");
            }

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
        $processingIds = $this->option('processing-id');

        $processingIds = array_values(array_filter(
            array_map(
                static fn (mixed $processingId): string => is_string($processingId) ? trim($processingId) : '',
                $processingIds,
            ),
        ));

        if ($processingIds === []) {
            throw new RuntimeException('At least one exact completed historic --processing-id is required.');
        }

        if (count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Each historic --processing-id must be listed exactly once.');
        }

        return $processingIds;
    }
}
