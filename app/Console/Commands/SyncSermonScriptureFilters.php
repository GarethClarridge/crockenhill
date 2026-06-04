<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Console\Command;

class SyncSermonScriptureFilters extends Command
{
    protected $signature = 'sermons:sync-scripture-filters
                            {--only-missing : Only index sermons that do not currently have derived filter rows}
                            {--sermon= : Restrict the sync to a single sermon ID}
                            {--dry-run : Preview what would be indexed without writing changes}';

    protected $description = 'Rebuild the derived sermon scripture browse filters from sermon references.';

    public function handle(SermonScriptureFilterIndexService $indexService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyMissing = (bool) $this->option('only-missing');
        $sermonOption = $this->option('sermon');

        $query = Sermon::query()
            ->select(['id', 'reference', 'content_type'])
            ->orderBy('id');

        if ($sermonOption !== null) {
            $sermonId = (int) $sermonOption;

            if ($sermonId < 1) {
                $this->error('The --sermon option must be a positive sermon ID.');

                return self::FAILURE;
            }

            $query->whereKey($sermonId);
        }

        if ($onlyMissing) {
            $query->doesntHave('scriptureFilters');
        }

        $sermonCount = $query->clone()->count();

        if ($sermonCount === 0) {
            $message = $sermonOption === null
                ? 'No sermons found to process.'
                : "Sermon [{$sermonOption}] was not found.";

            $this->warn($message);

            return $sermonOption === null ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            'Syncing scripture filters for %d sermon%s%s%s.',
            $sermonCount,
            $sermonCount !== 1 ? 's' : '',
            $onlyMissing ? ' (only missing)' : '',
            $dryRun ? ' (dry run)' : '',
        ));

        $counts = [
            'indexed' => 0,
            'cleared' => 0,
            'unparseable' => 0,
        ];

        $query->lazyById(200)->each(function (Sermon $sermon) use ($indexService, $dryRun, &$counts): void {
            $reference = is_string($sermon->reference) ? trim($sermon->reference) : '';

            if ($sermon->content_type !== SermonContentType::Sermon || $reference === '') {
                if (! $dryRun) {
                    $indexService->syncForSermon($sermon, []);
                }

                $counts['cleared']++;
                $this->writeVerboseStatus('cleared', $sermon, $reference === '' ? 'no reference' : 'non-public content');

                return;
            }

            $entries = $indexService->entriesForReference($reference);

            if ($entries === []) {
                if (! $dryRun) {
                    $indexService->syncForSermon($sermon, []);
                }

                $counts['unparseable']++;
                $this->writeVerboseStatus('unparseable', $sermon, $reference);

                return;
            }

            if (! $dryRun) {
                $indexService->syncForSermon($sermon, $entries);
            }

            $counts['indexed']++;
            $this->writeVerboseStatus('indexed', $sermon, sprintf('%s (%d entries)', $reference, count($entries)));
        });

        $this->info(sprintf(
            'Done. Indexed: %d, Cleared: %d, Unparseable: %d',
            $counts['indexed'],
            $counts['cleared'],
            $counts['unparseable'],
        ));

        return self::SUCCESS;
    }

    private function writeVerboseStatus(string $status, Sermon $sermon, string $detail): void
    {
        if (! $this->output->isVerbose()) {
            return;
        }

        $this->line(sprintf('  [%s] sermon %d: %s', $status, $sermon->id, $detail));
    }
}
