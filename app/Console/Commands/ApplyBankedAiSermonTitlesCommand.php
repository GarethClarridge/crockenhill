<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonTitleProvenance;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonSlugGenerator;
use Illuminate\Console\Command;

/**
 * Apply sermon titles that AI analysis already produced but never landed on the row.
 *
 * `ProcessTranscriptWithAI` writes the analysed title onto the sermon only when the
 * incumbent title's provenance permits it and no valid ID3 title is present. Runs that
 * banked an analysis but stalled — or failed — before that step left the analysis in
 * `media_processing_logs.ai_analysis` while the sermon kept the placeholder title
 * `SermonCreationService` created it with, of the form "Sunday 7Th May 2023".
 *
 * This replays exactly that decision from the banked analysis. It calls no provider and
 * re-runs no pipeline: the title already exists and has already been paid for. The
 * eligibility test is the model's own {@see Sermon::titleMayBeReplacedByAnalysis()},
 * so a curated title and a title already sourced from analysis are both left alone,
 * and a valid ID3 title still wins as an independent authoritative source.
 *
 * Slugs are deliberately not touched by default. These sermons are published, their
 * slugs were derived from the placeholder title ("sunday-7th-may-2023") rather than
 * from the pipeline's own placeholder pattern, and there is no slug-redirect table —
 * so rewriting one silently 404s a live URL. `--with-slug` opts into that, matching
 * what the pipeline would have done had the title landed at the right time.
 */
class ApplyBankedAiSermonTitlesCommand extends Command
{
    protected $signature = 'sermons:apply-banked-ai-titles
                            {--sermon=* : Limit to these sermon IDs}
                            {--with-slug : Also rebuild the slug, changing the public URL}
                            {--apply : Apply changes (the default is a dry run)}';

    protected $description = 'Apply sermon titles AI analysis produced but never wrote to the sermon';

    public function handle(SermonSlugGenerator $slugGenerator): int
    {
        $dryRun = ! (bool) $this->option('apply');
        $withSlug = (bool) $this->option('with-slug');

        /** @var list<string> $only */
        $only = (array) $this->option('sermon');
        $onlyIds = array_map(intval(...), $only);

        if ($dryRun) {
            $this->info('DRY RUN — no database changes will be made.');
        }

        $candidates = $this->candidates($onlyIds);

        if ($candidates === []) {
            $this->info('No sermons are carrying a placeholder title that banked analysis can replace.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($candidates as [$sermon, $title]) {
            $newSlug = $withSlug ? $slugGenerator->generate($title, $sermon->id) : null;

            $rows[] = [
                (string) $sermon->id,
                (string) $sermon->date,
                (string) $sermon->title,
                $title,
                $newSlug ?? '(unchanged)',
            ];

            if ($dryRun) {
                continue;
            }

            $sermon->title = $title;
            $sermon->title_provenance = SermonTitleProvenance::AiAnalysis;

            if ($newSlug !== null) {
                $sermon->slug = $newSlug;
            }

            $sermon->save();
        }

        $this->table(['Sermon', 'Date', 'Placeholder title', 'Banked title', 'Slug'], $rows);

        $this->info(sprintf(
            '%s %d sermon title(s).',
            $dryRun ? 'Would apply' : 'Applied',
            count($rows),
        ));

        if (! $withSlug) {
            $this->comment('Slugs were left as they are; pass --with-slug to rebuild them (this changes public URLs).');
        }

        return self::SUCCESS;
    }

    /**
     * Sermons whose banked analysis carries a title the incumbent provenance permits.
     *
     * @param  list<int>  $onlyIds
     * @return list<array{0: Sermon, 1: string}>
     */
    private function candidates(array $onlyIds): array
    {
        $candidates = [];

        MediaProcessingLog::query()
            ->whereNotNull('ai_analysis')
            ->whereNotNull('sermon_id')
            ->when($onlyIds !== [], fn ($query) => $query->whereIn('sermon_id', $onlyIds))
            ->orderBy('sermon_id')
            ->cursor()
            ->each(function (MediaProcessingLog $log) use (&$candidates): void {
                $sermon = Sermon::find($log->sermon_id);

                if (! $sermon instanceof Sermon) {
                    return;
                }

                $title = $log->ai_analysis?->title;

                if (! is_string($title) || trim($title) === '') {
                    return;
                }

                $title = trim($title);

                if ($title === trim((string) $sermon->title)) {
                    return;
                }

                if (! $sermon->titleMayBeReplacedByAnalysis()) {
                    return;
                }

                // A valid ID3 title is an independent authoritative source and outranks
                // analysis, exactly as it does in the pipeline.
                if (filled($log->processing_metadata?->id3Metadata?->title)) {
                    return;
                }

                $candidates[] = [$sermon, $title];
            });

        return $candidates;
    }
}
