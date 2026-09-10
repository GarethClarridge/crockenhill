<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongBoundaryEvidenceBackfill;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bank song boundary evidence for clips published before it was ever recorded.
 *
 * P8-Q10's residue — see {@see SongBoundaryEvidenceBackfill} for why each run is
 * reassessed inside its own staging context, and why an unreadable input is
 * reported rather than banked.
 *
 * Membership is re-derived from the absence of the evidence itself, so a second
 * run over the same corpus is a no-op.
 *
 * Deletion trigger: delete with {@see SongBoundaryEvidenceBackfill}.
 */
class BackfillSongBoundaryEvidenceCommand extends Command
{
    protected $signature = 'service:backfill-song-boundary-evidence
                            {--section=* : Restrict to these section IDs}
                            {--run=* : Restrict to these processing log IDs}
                            {--include-not-applicable : Also assess sections that are not published or pending}
                            {--execute : Bank the assessed evidence (default: dry run)}';

    protected $description = 'Assess and bank boundary evidence for song sections that hold none';

    public function handle(SongBoundaryEvidenceBackfill $backfill): int
    {
        $sections = $this->sectionsQuery()->get();

        if ($sections->isEmpty()) {
            $this->info('Every selected song section already holds banked boundary evidence.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Assessing %d song section(s) with no banked boundary evidence.', $sections->count()));

        $entries = $backfill->inspect($sections);
        $this->report($entries);

        if (! (bool) $this->option('execute')) {
            $this->warn('DRY RUN: nothing was written. Re-run with --execute to bank the evidence.');

            return self::SUCCESS;
        }

        $banked = 0;
        $held = 0;

        foreach ($entries as $entry) {
            if ($entry['disposition'] !== SongBoundaryEvidenceBackfill::DispositionAssessed) {
                continue;
            }

            $section = ServiceSection::find($entry['section']);

            if (! $section instanceof ServiceSection) {
                continue;
            }

            if ($backfill->apply($section)) {
                $banked++;

                if ($entry['holds']) {
                    $held++;
                }
            }
        }

        $this->info(sprintf('Banked evidence for %d section(s); %d newly hold for review.', $banked, $held));

        if ($held > 0) {
            $this->warn('Run `service:demote-held-publications` to take newly held published sections out of view.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{section:int, run:int|null, disposition:string, reactivated:bool, decision:string|null, risks:list<string>, reasons:list<string>, holds:bool, detail:string|null}>  $entries
     */
    private function report(array $entries): void
    {
        $byDisposition = [];
        $byReason = [];
        $unavailableRuns = [];

        foreach ($entries as $entry) {
            $byDisposition[$entry['disposition']] = ($byDisposition[$entry['disposition']] ?? 0) + 1;

            foreach ($entry['reasons'] as $reason) {
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }

            if ($entry['disposition'] === SongBoundaryEvidenceBackfill::DispositionUnavailable) {
                $unavailableRuns[$entry['run']] = true;
            }
        }

        foreach ($byDisposition as $disposition => $count) {
            $this->line(sprintf('  %-22s %d', $disposition, $count));
        }

        /*
         * The blast radius belongs in the dry run, not only in the execute
         * summary: banking a doubt sets needs_manual_review on an already
         * published clip, which `service:demote-held-publications` then takes
         * out of view. An operator has to see that count before deciding.
         */
        $holds = count(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['holds']
                && $entry['disposition'] === SongBoundaryEvidenceBackfill::DispositionAssessed,
        ));

        if ($holds > 0) {
            $this->line(sprintf('  %-22s %d', 'would newly hold', $holds));
        }

        if ($byReason !== []) {
            arsort($byReason);
            $this->line('  review reasons named:');

            foreach ($byReason as $reason => $count) {
                $this->line(sprintf('    %-42s %d', $reason, $count));
            }
        }

        if ($unavailableRuns !== []) {
            /*
             * Named rather than summarised: an unavailable input means the run's
             * transcript or RMS log cannot be read even after reactivation, which
             * is a restage question, not a policy one.
             */
            $this->warn(sprintf(
                '  inputs unreadable for run(s): %s — these need their source restaged before evidence exists to bank.',
                implode(', ', array_keys($unavailableRuns)),
            ));
        }
    }

    /**
     * @return Builder<ServiceSection>
     */
    private function sectionsQuery(): Builder
    {
        $query = ServiceSection::query()
            ->notSuperseded()
            ->where('section_type', ServiceSectionType::Song->value)
            ->whereNull('metadata->'.SongPublicationBoundaryEvidenceService::METADATA_KEY)
            ->orderBy('id');

        if (! (bool) $this->option('include-not-applicable')) {
            /*
             * A not_applicable section has no clip to defend, and would be
             * assessed by the handler if it ever became one. Restricting the
             * default keeps the pass to the sections whose bounds are actually
             * standing behind something.
             */
            $query->whereIn('publication_status', [
                ServiceSectionPublicationStatus::Published->value,
                ServiceSectionPublicationStatus::PendingApproval->value,
            ]);
        }

        $sectionIds = array_map('intval', (array) $this->option('section'));

        if ($sectionIds !== []) {
            $query->whereIn('id', $sectionIds);
        }

        $runIds = array_map('intval', (array) $this->option('run'));

        if ($runIds !== []) {
            $query->whereIn('media_processing_log_id', $runIds);
        }

        return $query;
    }
}
