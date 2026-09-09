<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\PublishedSectionReconciler;
use Illuminate\Console\Command;

/**
 * Take published sections back out of view when their own state no longer
 * supports publication (P8-Q16 gap 1).
 *
 * This is a demotion of public state, so it reports without writing unless
 * `--apply` is passed and names every section it would touch, with the reason
 * and whether anyone can currently see it.
 *
 * It is a command rather than a change to `PrepareSectionPublicationCandidates`
 * on purpose. That job runs inside the pipeline, and every re-extraction passes
 * through it — the 30 runs of the P8-Q13a repair among them. Demoting from
 * there would let a routine re-cut withdraw public content with nobody deciding
 * to. The job's refusal to demote stays; what was missing is anything that ever
 * asks the question.
 */
class DemoteHeldPublicationsCommand extends Command
{
    protected $signature = 'service:demote-held-publications
        {--section=* : Service section ids to reconcile}
        {--all : Reconcile every published section}
        {--public-only : Restrict to sections whose media is currently visible publicly}
        {--apply : Demote the sections listed (default: dry run)}
        {--json : Emit the whole report as JSON}';

    protected $description = 'Demote published sections that are held for review or no longer eligible';

    public function handle(PublishedSectionReconciler $reconciler): int
    {
        $sectionIds = array_map('intval', (array) $this->option('section'));

        if ($sectionIds === [] && ! (bool) $this->option('all')) {
            $this->error('Name what to reconcile: --section, or --all.');

            return self::FAILURE;
        }

        $query = ServiceSection::query()
            ->where('publication_status', ServiceSectionPublicationStatus::Published->value)
            ->orderBy('id');

        if ($sectionIds !== []) {
            $found = $query->clone()->whereIn('id', $sectionIds)->pluck('id')->all();
            $missing = array_values(array_diff($sectionIds, $found));

            if ($missing !== []) {
                // Named sections that are not published are an error rather than an
                // empty pass: silently reporting "nothing to do" is how a demotion
                // aimed at real public content quietly does nothing.
                $this->error(
                    'These sections could not be found in a published state: '.implode(', ', $missing)
                    .'. Check nothing has repointed the database, such as a Dusk run in progress.'
                );

                return self::FAILURE;
            }

            $query->whereIn('id', $sectionIds);
        }

        $report = [
            'applied' => (bool) $this->option('apply'),
            'published' => 0,
            'demotable' => 0,
            'publicly_visible' => 0,
            'refused' => 0,
            'sections_demoted' => 0,
            'videos_quarantined' => 0,
            'sections' => [],
        ];

        foreach ($query->lazyById() as $section) {
            $report['published']++;
            $assessment = $reconciler->assess($section);

            if ($assessment === null) {
                continue;
            }

            $visible = $reconciler->isPubliclyVisible($section);

            if ((bool) $this->option('public-only') && ! $visible) {
                continue;
            }

            $refusal = $reconciler->refusal($section);
            $report['demotable']++;

            if ($visible) {
                $report['publicly_visible']++;
            }

            if ($refusal !== null) {
                $report['refused']++;
            }

            $report['sections'][] = [
                'section' => $section->id,
                'run' => $section->media_processing_log_id,
                'type' => $section->section_type->value,
                'minutes' => round(((float) $section->end_time - (float) $section->start_time) / 60, 1),
                'reason' => $assessment['reason'],
                'detail' => $assessment['detail'],
                'publicly_visible' => $visible,
                'refused' => $refusal,
            ];

            if ((bool) $this->option('apply') && $refusal === null) {
                $outcome = $reconciler->demote($section, $assessment);
                $report['sections_demoted'] += $outcome['section'] ? 1 : 0;
                $report['videos_quarantined'] += $outcome['video'] ? 1 : 0;
            }
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info(sprintf(
            '%s %d published sections: %d should not be published, %d of them currently visible, %d refused.',
            $report['applied'] ? 'Reconciled' : 'Assessed',
            $report['published'],
            $report['demotable'],
            $report['publicly_visible'],
            $report['refused'],
        ));

        if ($report['sections'] !== []) {
            $this->table(
                ['Section', 'Run', 'Type', 'Minutes', 'Public', 'Reason', 'Detail'],
                array_map(static fn (array $row): array => [
                    $row['section'],
                    $row['run'],
                    $row['type'],
                    $row['minutes'],
                    $row['publicly_visible'] ? 'YES' : 'no',
                    $row['refused'] === null ? $row['reason'] : 'REFUSED',
                    mb_strimwidth($row['refused'] ?? $row['detail'], 0, 68, '…'),
                ], $report['sections']),
            );
        }

        if ($report['applied']) {
            $this->info(sprintf(
                '%d sections demoted to not_applicable; %d song videos quarantined.',
                $report['sections_demoted'],
                $report['videos_quarantined'],
            ));
            $this->warn('Demotion is not deletion: every video file and row is intact, and the section keeps the review hold that explains it.');

            return;
        }

        $this->warn('Nothing was written. Pass --apply to demote the sections listed.');
    }
}
