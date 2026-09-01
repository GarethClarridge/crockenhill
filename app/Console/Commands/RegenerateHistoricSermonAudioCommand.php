<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricSermonAudioRegeneration;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Re-derive sermon audio that a historic run produced and a later cleanup
 * removed, so custody repair has both assets it requires.
 *
 * Deletion trigger: Delete once the historic pilot cohort's custody is repaired
 * and the historic import's closeout retention window has expired.
 */
class RegenerateHistoricSermonAudioCommand extends Command
{
    protected $signature = 'historic-import:regenerate-sermon-audio
                            {--operation= : Exact immutable historic operation that owns the runs}
                            {--processing-id=* : Exact historic processing ID; repeat for every run}
                            {--apply : Re-derive the missing audio (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Re-derive missing historic sermon audio from its surviving sermon video';

    public function handle(HistoricSermonAudioRegeneration $regeneration): int
    {
        try {
            $operation = $this->operation();
            $processingIds = $this->processingIds();
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entries = $regeneration->inspect($operation, $processingIds);

            $this->table(
                ['Processing ID', 'Sermon', 'Disk', 'Video duration', 'Audio path', 'Disposition'],
                array_map(static fn (array $entry): array => [
                    $entry['processing_id'],
                    (string) $entry['sermon']->id,
                    $entry['disk'],
                    number_format($entry['video_duration'], 3).' s',
                    $entry['audio_path'],
                    $entry['disposition'],
                ], $entries),
            );

            $this->line('Audio is re-derived from the surviving cut video through the same extraction path the pipeline used.');

            if (! $apply) {
                $this->warn('DRY RUN: no audio was written. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $result = $regeneration->apply($entries);

            $this->info(sprintf(
                'Regenerated %d sermon audio file(s) (%s bytes); already present: %d.',
                $result['regenerated'],
                number_format($result['bytes']),
                $result['already_present'],
            ));

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
