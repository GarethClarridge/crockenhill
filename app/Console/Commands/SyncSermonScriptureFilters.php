<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Services\SermonScriptureFilterIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
            ->withCount('scriptureFilters')
            ->orderBy('id');

        if ($sermonOption !== null) {
            $sermonId = (int) $sermonOption;

            if ($sermonId < 1) {
                $this->error('The --sermon option must be a positive sermon ID.');

                return self::FAILURE;
            }

            $query->whereKey($sermonId);
        }

        /** @var Collection<int, Sermon> $sermons */
        $sermons = $query->get();

        if ($sermons->isEmpty()) {
            $message = $sermonOption === null
                ? 'No sermons found to process.'
                : "Sermon [{$sermonOption}] was not found.";

            $this->warn($message);

            return $sermonOption === null ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            'Syncing scripture filters for %d sermon(s)%s%s.',
            $sermons->count(),
            $onlyMissing ? ' (only missing)' : '',
            $dryRun ? ' (dry run)' : '',
        ));

        $counts = [
            'indexed' => 0,
            'cleared' => 0,
            'unparseable' => 0,
            'skipped' => 0,
        ];

        foreach ($sermons as $sermon) {
            if ($onlyMissing && (int) $sermon->scripture_filters_count > 0) {
                $counts['skipped']++;

                continue;
            }

            $reference = is_string($sermon->reference) ? trim($sermon->reference) : '';

            if ($sermon->content_type !== SermonContentType::Sermon || $reference === '') {
                if (! $dryRun) {
                    $sermon->scriptureFilters()->delete();
                }

                $counts['cleared']++;

                continue;
            }

            $entries = $indexService->entriesForReference($reference);

            if ($entries === []) {
                if (! $dryRun) {
                    $sermon->scriptureFilters()->delete();
                }

                $counts['unparseable']++;

                continue;
            }

            if (! $dryRun) {
                $indexService->syncForSermon($sermon);
            }

            $counts['indexed']++;
        }

        $this->info(sprintf(
            'Done. Indexed: %d, Cleared: %d, Unparseable: %d, Skipped: %d',
            $counts['indexed'],
            $counts['cleared'],
            $counts['unparseable'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
