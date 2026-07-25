<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sermon;
use App\Services\Sermon\SermonSlugGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-slugs sermons left with a placeholder URL after the AI title landed.
 *
 * The livestream pipeline creates the sermon record (SubmitToProcessing) several
 * jobs before the AI title is known (ProcessTranscriptWithAI), so historically
 * the title was upgraded while the slug kept its "evening-sermon-may-3-2026"
 * placeholder. ProcessTranscriptWithAI now carries the title through to the slug;
 * this command repairs the records created before that change.
 *
 * Changing a slug changes the sermon's public URL and the old one will 404 —
 * there is no slug-redirect table. Podcast subscribers are unaffected because the
 * feed's GUID is keyed on the sermon ID, not the slug.
 */
class ReslugPlaceholderSermonsCommand extends Command
{
    protected $signature = 'sermons:reslug-placeholders
                            {--sermon=* : Limit to these sermon IDs}
                            {--apply : Write changes (default: dry-run — nothing is persisted)}';

    protected $description = 'Rebuild placeholder sermon slugs from the titles AI analysis produced';

    public function handle(SermonSlugGenerator $slugGenerator): int
    {
        $apply = (bool) $this->option('apply');
        /** @var list<string> $sermonIds */
        $sermonIds = (array) $this->option('sermon');

        $candidates = $this->placeholderSlugSermons($slugGenerator, $sermonIds);

        $rows = [];

        foreach ($candidates as $sermon) {
            $newSlug = $slugGenerator->generate($sermon->title, $sermon->id);

            if ($newSlug === $sermon->slug) {
                continue;
            }

            $rows[] = [
                $sermon->id,
                $sermon->date->toDateString(),
                $sermon->title,
                $sermon->slug,
                $newSlug,
            ];

            if ($apply) {
                $sermon->slug = $newSlug;
                $sermon->save();
            }
        }

        if ($rows === []) {
            $this->info('No sermons are carrying a placeholder slug.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? '<fg=yellow>APPLYING</> — rebuilding slugs from titles:'
            : '<fg=cyan>DRY RUN</> — the following slugs would be rebuilt (re-run with --apply to persist):');
        $this->newLine();

        $this->table(['id', 'date', 'title', 'current slug', 'new slug'], $rows);

        $this->newLine();
        $this->info(sprintf('%s %d sermon(s).', $apply ? 'Re-slugged' : 'Would re-slug', count($rows)));

        if ($apply) {
            $this->warn('The previous URLs now 404. Submit the new canonical URLs for reindexing.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment('No changes written. Re-run with --apply to persist.');

        return self::SUCCESS;
    }

    /**
     * Sermons whose slug is still a pipeline placeholder.
     *
     * @param  list<string>  $sermonIds
     * @return Collection<int, Sermon>
     */
    private function placeholderSlugSermons(SermonSlugGenerator $slugGenerator, array $sermonIds): Collection
    {
        return Sermon::query()
            ->when($sermonIds !== [], fn ($query) => $query->whereIn('id', $sermonIds))
            ->orderBy('date')
            ->get()
            ->filter(fn (Sermon $sermon): bool => $slugGenerator->isPlaceholderSlug($sermon->slug))
            ->values();
    }
}
