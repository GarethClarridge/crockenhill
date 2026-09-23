<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Hold an exact, named set of sections whose content has been proven wrong.
 *
 * Membership is all or nothing. A named section that does not exist, or whose type
 * the release gate cannot refuse on, stops the whole run before anything is
 * written: a partial hold over a list someone assembled from evidence reports
 * containment for rows it never reached.
 */
class HoldSectionContentCommand extends Command
{
    protected $signature = 'service:hold-section-content
        {--section=* : Service section IDs to hold}
        {--reason= : Why the content cannot be accepted as it stands}
        {--evidence= : Where the proof is recorded, such as a register, file or plan section}
        {--found-by= : The check that found the defect: loop_screen, lyric_comparison, source_audio, media_measurement, boundary, judgement or decision}
        {--execute : Record the holds; without this option the command is a dry run}';

    protected $description = 'Hold named sermon, children\'s talk or song sections whose content is proven wrong';

    public function handle(
        HoldSectionForContentReview $hold,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ): int {
        $sectionIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->option('section'),
        ))));
        $reason = trim((string) $this->option('reason'));
        $evidence = trim((string) $this->option('evidence'));

        if ($sectionIds === []) {
            $this->error('Name at least one section with --section.');

            return self::FAILURE;
        }

        if ($reason === '' || $evidence === '') {
            $this->error('Both --reason and --evidence are required; a hold nobody can explain cannot be resolved.');

            return self::FAILURE;
        }

        $foundBy = ContentHoldCheck::tryFrom(trim((string) $this->option('found-by')));

        if ($foundBy === null) {
            $this->error('--found-by must be one of: '.implode(', ', array_column(ContentHoldCheck::cases(), 'value')).'. The check that found a hold is what can re-test it after a repair.');

            return self::FAILURE;
        }

        $sections = ServiceSection::query()
            ->with('processingLog')
            ->whereKey($sectionIds)
            ->orderBy('id')
            ->get();

        $missing = array_values(array_diff($sectionIds, $sections->modelKeys()));
        $unholdable = $sections->reject(
            static fn (ServiceSection $section): bool => HoldSectionForContentReview::canHold($section),
        );

        if ($missing !== [] || $unholdable->isNotEmpty()) {
            foreach ($missing as $id) {
                $this->error("Section {$id} does not exist.");
            }

            foreach ($unholdable as $section) {
                $this->error("Section {$section->id} is a {$section->section_type->value}; the release gate does not refuse on a hold there.");
            }

            $this->error('Nothing was held: the named membership must be exact.');

            return self::FAILURE;
        }

        $this->table(
            ['Section', 'Run', 'Type', 'Held now', 'Content hold', 'Refuses at release'],
            $sections->map(fn (ServiceSection $section): array => [
                $section->id,
                $section->media_processing_log_id,
                $section->section_type->value,
                $section->needs_manual_review ? 'yes' : 'no',
                HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? []) ? 'yes' : 'no',
                $this->refusedAtRelease($section),
            ])->all(),
        );

        if (! (bool) $this->option('execute')) {
            $this->warn('DRY RUN: nothing was written. Re-run with --execute to record these holds.');

            return self::SUCCESS;
        }

        $changed = 0;

        DB::transaction(function () use ($sections, $hold, $reason, $evidence, $foundBy, &$changed): void {
            foreach ($sections as $section) {
                if ($hold($section, $reason, $evidence, $foundBy)) {
                    $changed++;
                }
            }
        });

        ChurchService::query()
            ->whereKey($this->serviceIdsFor($sections))
            ->each(fn (ChurchService $service) => $reviewSynchronizer->reconcileServiceReview($service));

        $this->info(sprintf(
            'Held %d section(s); %d already carried this hold.',
            $changed,
            $sections->count() - $changed,
        ));

        return self::SUCCESS;
    }

    private function refusedAtRelease(ServiceSection $section): string
    {
        if ($section->section_type === ServiceSectionType::Song) {
            $videoIds = SongVideo::query()->where('service_section_id', $section->id)->pluck('id')->all();

            return $videoIds === [] ? 'no song video' : 'song video '.implode(', ', $videoIds);
        }

        $sermonId = $section->processingLog->sermon_id;

        return $sermonId === null ? 'no sermon on run' : "sermon {$sermonId}";
    }

    /**
     * A section reaches its service through its run or its projected item, and the
     * service's own review state is derived from whichever sections it can see.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return list<int>
     */
    private function serviceIdsFor(EloquentCollection $sections): array
    {
        $ids = [];

        foreach ($sections as $section) {
            $ids[] = $section->processingLog->church_service_id;
            $ids[] = $section->churchServiceItem?->church_service_id;
        }

        return array_values(array_unique(array_filter($ids, 'is_int')));
    }
}
