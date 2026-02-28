<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;
use Illuminate\Console\Command;

class BackfillMediaProcessingIdentityCommand extends Command
{
    protected $signature = 'media-processing:backfill-extracted-identity
        {--dry-run : Preview backfill changes without writing to the database}
        {--chunk=200 : Number of rows to process per chunk}';

    protected $description = 'Backfill extracted_date/extracted_service from processing_metadata for historical media logs';

    public function handle(MediaProcessingIdentityResolver $identityResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $query = MediaProcessingLog::query()
            ->whereNotNull('processing_metadata')
            ->where(function ($query): void {
                $query->whereNull('extracted_date')
                    ->orWhereNull('extracted_service');
            });

        $candidateCount = (clone $query)->count();

        if ($candidateCount === 0) {
            $this->info('No media processing logs require backfill.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN enabled. No rows will be updated.');
        }

        $metrics = [
            'candidates' => $candidateCount,
            'processed' => 0,
            'would_update' => 0,
            'updated' => 0,
            'skipped_no_usable_metadata' => 0,
            'skipped_no_changes_required' => 0,
        ];

        $query->orderBy('id')->chunkById($chunkSize, function ($logs) use (&$metrics, $dryRun, $identityResolver): void {
            foreach ($logs as $log) {
                $metrics['processed']++;

                $metadata = is_array($log->processing_metadata) ? $log->processing_metadata : [];
                $parsedDate = $identityResolver->parseDate($metadata['extracted_date'] ?? null);
                $parsedService = $identityResolver->parseService($metadata['extracted_service'] ?? null);

                $payload = [];

                if ($log->extracted_date === null && $parsedDate !== null) {
                    $payload['extracted_date'] = $parsedDate;
                }

                if ($log->extracted_service === null && $parsedService instanceof SermonService) {
                    $payload['extracted_service'] = $parsedService->value;
                }

                if ($payload === []) {
                    $hasUsableDate = $parsedDate !== null;
                    $hasUsableService = $parsedService instanceof SermonService;

                    if (! $hasUsableDate && ! $hasUsableService) {
                        $metrics['skipped_no_usable_metadata']++;
                    } else {
                        $metrics['skipped_no_changes_required']++;
                    }

                    continue;
                }

                if ($dryRun) {
                    $metrics['would_update']++;

                    continue;
                }

                $log->update($payload);
                $metrics['updated']++;
            }
        });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Candidates', $metrics['candidates']],
                ['Processed', $metrics['processed']],
                ['Would update', $metrics['would_update']],
                ['Updated', $metrics['updated']],
                ['Skipped (no usable metadata)', $metrics['skipped_no_usable_metadata']],
                ['Skipped (no changes required)', $metrics['skipped_no_changes_required']],
            ]
        );

        return self::SUCCESS;
    }
}
