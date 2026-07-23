<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\SectionItemRealigner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Repairs historical song-section links shifted onto the wrong plan items.
 *
 * Deletion trigger: remove this one-shot command after the production song-item
 * alignment audit and all approved historical realignments have completed.
 */
class RealignSectionItemsCommand extends Command
{
    protected $signature = 'service:realign-section-items
                            {--service=* : Limit to these church service IDs}
                            {--include-superseded : Also repair superseded runs}
                            {--apply : Write changes (default: dry-run)}';

    protected $description = 'Realign detected song sections to plan items by song ID then normalized title';

    public function handle(SectionItemRealigner $realigner): int
    {
        $apply = (bool) $this->option('apply');
        $changesByRun = [];

        foreach ($this->runs() as $run) {
            $changes = $realigner->preview($run);
            if ($changes !== []) {
                $changesByRun[] = ['run' => $run, 'changes' => $changes];
            }
        }

        if ($changesByRun === []) {
            $this->info('No section-item realignments found for the given scope.');

            return self::SUCCESS;
        }

        $this->line($apply ? '<fg=yellow>APPLYING</> — realigning song sections:' : '<fg=cyan>DRY RUN</> — song sections that would be realigned:');
        $rows = [];
        $count = 0;

        foreach ($changesByRun as $entry) {
            foreach ($entry['changes'] as $change) {
                $count++;
                $rows[] = [
                    $entry['run']->church_service_id,
                    $entry['run']->id,
                    $change['section']->id,
                    $change['section']->title,
                    $change['from_item_id'] ?? '—',
                    $change['to_item_id'] ?? '—',
                    $change['match'],
                ];
            }

            if ($apply) {
                $realigner->apply($entry['changes']);
            }
        }

        $this->table(['svc', 'run', 'section', 'detected song', 'from item', 'to item', 'match'], $rows);
        $this->info(sprintf('%s %d section(s).', $apply ? 'Realigned' : 'Would realign', $count));
        if (! $apply) {
            $this->comment('No changes written. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, MediaProcessingLog>
     */
    private function runs(): Collection
    {
        /** @var list<string> $serviceIds */
        $serviceIds = (array) $this->option('service');
        $includeSuperseded = (bool) $this->option('include-superseded');

        return MediaProcessingLog::query()
            ->whereNotNull('church_service_id')
            ->whereHas('serviceSections', fn (Builder $query): Builder => $query->where('section_type', 'song'))
            ->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('church_service_id', $serviceIds))
            ->when(! $includeSuperseded, fn (Builder $query): Builder => $query->whereNull('superseded_at'))
            ->orderBy('id')
            ->get();
    }
}
