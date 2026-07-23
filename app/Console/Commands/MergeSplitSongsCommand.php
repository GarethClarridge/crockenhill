<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\SongContinuationMerger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Repairs historical song sections split into adjacent continuation fragments.
 *
 * Deletion trigger: remove this one-shot command after the production split-song
 * audit and all approved historical merges have completed.
 */
class MergeSplitSongsCommand extends Command
{
    protected $signature = 'service:merge-split-songs
                            {--service=* : Limit to these church service IDs}
                            {--include-superseded : Also repair superseded runs}
                            {--apply : Write changes (default: dry-run)}';

    protected $description = 'Preview or merge adjacent song continuation fragments';

    public function handle(SongContinuationMerger $merger): int
    {
        $apply = (bool) $this->option('apply');
        $groups = [];

        foreach ($this->runs() as $run) {
            foreach ($merger->preview($run, conservative: false) as $group) {
                $groups[] = ['run' => $run, ...$group];
            }
        }

        if ($groups === []) {
            $this->info('No split-song merge groups found for the given scope.');

            return self::SUCCESS;
        }

        $this->line($apply ? '<fg=yellow>APPLYING</> — merging split songs:' : '<fg=cyan>DRY RUN</> — split songs that would be merged:');
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = [
                $group['run']->church_service_id,
                $group['run']->id,
                $group['anchor']->id,
                $group['anchor']->title,
                $group['absorbed']->pluck('id')->implode(', '),
                sprintf('%.3f–%.3f', $group['anchor']->start_time, $group['absorbed']->max('end_time')),
            ];

            if ($apply) {
                $merger->apply([
                    'anchor' => $group['anchor'],
                    'absorbed' => $group['absorbed'],
                ], 'repair_command');
            }
        }

        $this->table(['svc', 'run', 'anchor', 'song', 'absorbed', 'range'], $rows);
        $this->info(sprintf('%s %d group(s).', $apply ? 'Merged' : 'Would merge', count($groups)));
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
            ->whereHas('serviceSections')
            ->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('church_service_id', $serviceIds))
            ->when(! $includeSuperseded, fn (Builder $query): Builder => $query->whereNull('superseded_at'))
            ->orderBy('id')
            ->get();
    }
}
