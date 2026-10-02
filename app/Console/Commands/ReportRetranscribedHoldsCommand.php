<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HoldSectionForContentReview;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\RetranscribedHoldReview;
use Illuminate\Console\Command;

/**
 * List every live hold on a sermon or talk with its passage measured in the run's current text,
 * proposing which look restored (see {@see RetranscribedHoldReview}). Run after Tier A; release
 * what listening confirms with `service:release-content-hold`. Reads only.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class ReportRetranscribedHoldsCommand extends Command
{
    protected $signature = 'historic-import:held-transcript-report
        {runs?* : Processing log ids; every historic run with a held sermon or talk when omitted}
        {--all : List every live hold, not only those whose text has changed since it was raised}
        {--report= : Write the JSON report here}';

    protected $description = 'Propose which held sermons and talks read as restored in their re-transcribed text, read-only';

    public function handle(RetranscribedHoldReview $review): int
    {
        $runIds = array_values(array_filter(array_map('intval', (array) $this->argument('runs'))));

        if ($runIds === []) {
            $runIds = ServiceSection::query()
                ->whereJsonContains('metadata->review_flags', HoldSectionForContentReview::FLAG)
                ->whereHas('processingLog', static fn ($query) => $query->whereNotNull('historic_import_operation_id'))
                ->distinct()
                ->orderBy('media_processing_log_id')
                ->pluck('media_processing_log_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $rows = [];

        foreach (MediaProcessingLog::query()->whereKey($runIds)->orderBy('id')->get() as $run) {
            foreach ($review->review($run) as $row) {
                if ($this->option('all') || $row['text_changed_since_hold'] !== false) {
                    $rows[] = $row;
                }
            }
        }

        $this->table(
            ['Run', 'Section', 'Held at', 'Loss?', 'Words/min', 'Longest gap', 'Verdict', 'Why'],
            array_map(static fn (array $row): array => [
                $row['run'],
                $row['section'],
                $row['held_at'] ?? '–',
                $row['transcript_loss'] ? 'yes' : 'no',
                $row['measures']['words_per_minute'] ?? '–',
                isset($row['measures']) ? $row['measures']['longest_gap_seconds'].' s' : '–',
                $row['verdict'],
                implode('; ', $row['why']),
            ], $rows),
        );

        $counts = array_count_values(array_column($rows, 'verdict'));
        ksort($counts);
        $this->line(implode(', ', array_map(static fn (string $verdict, int $count): string => "{$count} {$verdict}", array_keys($counts), $counts)) ?: 'No live hold matches.');

        $path = $this->option('report');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['generated_at' => now()->toIso8601String(), 'holds' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info("Report written to {$path}");
        }

        return self::SUCCESS;
    }
}
