<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricPreM2VideoStorageRegistration;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Register sermon-video storage that a pre-M2 historic run completed before the
 * tracking existed, so its promotion and cleanup tail can be recovered.
 *
 * Deletion trigger: Delete once every pre-M2 historic run has a truthful
 * terminal disposition and the historic import's closeout retention window has
 * expired.
 */
class RegisterPreM2VideoStorageCommand extends Command
{
    protected $signature = 'historic-import:register-pre-m2-video-storage
                            {--operation= : Exact immutable historic operation that owns the runs}
                            {--processing-id=* : Exact pre-M2 processing ID; repeat for every run}
                            {--apply : Write the registration (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Register verified pre-M2 historic sermon video storage so its tail can be recovered';

    public function handle(HistoricPreM2VideoStorageRegistration $registration): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entries = $registration->inspect($operation, $processingIds);

            $this->table(
                ['Processing ID', 'Sermon', 'Disk', 'Asset', 'Bytes', 'Probed duration', 'Disposition'],
                array_map(static fn (array $entry): array => [
                    $entry['processing_id'],
                    (string) $entry['sermon']->id,
                    $entry['asset_disk'],
                    $entry['asset_path'],
                    number_format($entry['asset_bytes']),
                    number_format($entry['asset_duration'], 3).' s',
                    $entry['disposition'],
                ], $entries),
            );

            $this->line('Registration is accepted only on durable evidence: the video exists, holds bytes and probes as real media.');

            if (! $apply) {
                $this->warn('DRY RUN: no registration was written. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $result = $registration->apply($operation, $entries);

            $this->info("Registered {$result['registered']} pre-M2 video storage record(s); already registered: {$result['already_registered']}.");

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
            throw new RuntimeException("Historic import operation {$operationId} does not exist.");
        }

        return $operation;
    }

    /** @return list<string> */
    private function processingIds(): array
    {
        $processingIds = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $this->option('processing-id'),
            ),
        ));

        if ($processingIds === []) {
            throw new RuntimeException('At least one exact processing ID is required.');
        }

        return $processingIds;
    }
}
