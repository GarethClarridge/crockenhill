<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Restores song plan-item titles that were overwritten by the deleted heuristic aligner.
 *
 * At OpenLP import a song item's `title` equals its `source_title`
 * (OpenLpServiceParser: `$title = $sourceTitle`). The now-deleted heuristic
 * aligner wrote matched-section titles back onto plan items, desyncing `title`
 * from the pristine `source_title` — and from `openlp_search_title` / `song_id`,
 * which still follow the original OoS text. The timeline renders `title` as the
 * "Planned:" label, so a desynced item misrepresents which song the plan slot is.
 *
 * Restoring `title := source_title` reinstates the exact imported value.
 */
class ResyncDesyncedItemTitlesCommand extends Command
{
    protected $signature = 'service:resync-item-titles
                            {--service=* : Limit to these church service IDs}
                            {--apply : Write changes (default: dry-run — nothing is persisted)}';

    protected $description = 'Restore song plan-item titles from source_title where the heuristic aligner desynced them';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        /** @var list<string> $serviceIds */
        $serviceIds = (array) $this->option('service');

        $items = $this->desyncedItems($serviceIds);

        if ($items->isEmpty()) {
            $this->info('No desynced song item titles found for the given scope.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? '<fg=yellow>APPLYING</> — restoring title := source_title:'
            : '<fg=cyan>DRY RUN</> — the following titles would be restored (re-run with --apply to persist):');
        $this->newLine();

        $rows = [];

        foreach ($items as $item) {
            $song = $item->song;
            $rows[] = [
                $item->church_service_id,
                $item->id,
                (string) $item->title,
                (string) $item->source_title,
                $song instanceof Song ? (string) $song->title : '—',
            ];

            if ($apply) {
                $item->title = (string) $item->source_title;
                $item->save();
            }
        }

        $this->table(
            ['svc', 'item', 'current title (wrong)', 'restored title (source_title)', 'linked song'],
            $rows,
        );

        $this->newLine();
        $this->info(sprintf(
            '%s %d item(s) across %d service(s).',
            $apply ? 'Resynced' : 'Would resync',
            $items->count(),
            $items->pluck('church_service_id')->unique()->count(),
        ));

        if (! $apply) {
            $this->newLine();
            $this->comment('No changes written. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Song items whose title diverges from their pristine imported source_title.
     *
     * @param  list<string>  $serviceIds
     * @return Collection<int, ChurchServiceItem>
     */
    private function desyncedItems(array $serviceIds): Collection
    {
        return ChurchServiceItem::query()
            ->with('song')
            ->where('type', 'songs')
            ->whereNotNull('source_title')
            ->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('church_service_id', $serviceIds))
            ->whereColumn('title', '!=', 'source_title')
            ->orderBy('church_service_id')
            ->orderBy('position')
            ->get();
    }
}
