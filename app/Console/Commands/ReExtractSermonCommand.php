<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-cut a finished run's sermon from the structure it already has.
 *
 * Confirming or correcting a service's structure moves where the sermon starts
 * and ends, so the published audio has to be rebuilt. Re-running the whole
 * pipeline would re-transcribe and re-detect to get there — expensive, and the
 * detector is not deterministic across passes, so it can answer differently and
 * undo the correction being published. This resumes at the extraction phase
 * instead, keeping the sections, transcript and RMS log exactly as they are.
 *
 * A plan that falls back to the run's recorded bounds is refused, dry run
 * included: it is not a cut from the structure, and the extraction job judges
 * such a plan against the RMS blocks and may refuse it after this command has
 * reported success. A content-held sermon is the common cause; naming it with
 * `--held-section` re-cuts its span and leaves the hold in place.
 */
class ReExtractSermonCommand extends Command
{
    protected $signature = 'sermons:re-extract
                            {processing_id : The processing ID of the run to re-cut}
                            {--held-section= : A content-held sermon section of this run whose span is right to re-cut; its hold stays}
                            {--dry-run : Report the span change without dispatching}
                            {--yes : Skip the confirmation prompt (for non-interactive use)}';

    protected $description = "Re-cut a finished run's sermon from its existing service structure";

    public function handle(
        SermonExtractionPlanResolver $planResolver,
        ProcessingRunOrchestrator $orchestrator,
        HistoricStagingContextRegistry $stagingContextRegistry,
    ): int {
        $processingId = (string) $this->argument('processing_id');

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error("No processing run found for {$processingId}.");

            return self::FAILURE;
        }

        if ($processingLog->serviceSections()->count() === 0) {
            $this->error('This run has no service sections, so there is no structure to re-cut from.');

            return self::FAILURE;
        }

        $heldSection = null;
        $heldSectionOption = $this->option('held-section');

        if ($heldSectionOption !== null) {
            $heldSection = $this->heldSermonOf($processingLog, (int) $heldSectionOption);

            if (! $heldSection instanceof ServiceSection) {
                $this->error("--held-section must name a content-held sermon section of this run; {$heldSectionOption} is not one.");

                return self::FAILURE;
            }
        }

        $heldSpanAuthority = $heldSection instanceof ServiceSection
            ? MediaProcessingLog::heldSermonSpanAuthorityFor($heldSection)
            : null;

        $stagingContext = $processingLog->historicStagingContext();
        $inspect = fn (callable $callback): mixed => $stagingContext === null
            ? $callback()
            : $stagingContextRegistry->within($stagingContext, \Closure::fromCallable($callback));

        /** @var array{mode: string, source: string, segments: array<int, array{start_time: float, end_time: float}>, metadata: array<string, mixed>} $plan */
        $plan = $inspect(fn (): array => $planResolver->resolve($processingLog, $heldSpanAuthority));
        $segments = $plan['segments'];

        if ($segments === []) {
            $this->error('The extraction plan resolved no segments; nothing would be published.');

            return self::FAILURE;
        }

        $newStart = (float) $segments[0]['start_time'];
        $newEnd = (float) $segments[array_key_last($segments)]['end_time'];

        $this->line(sprintf('Run:      %s (%s)', $processingId, $processingLog->status->value));
        $this->line(sprintf('Recorded: %.1fs - %.1fs', (float) $processingLog->sermon_start_time, (float) $processingLog->sermon_end_time));
        $this->line(sprintf('Planned:  %.1fs - %.1fs  [%s from %s]', $newStart, $newEnd, (string) ($plan['metadata']['strategy'] ?? 'unknown'), $plan['source']));

        if (($plan['metadata']['requires_review'] ?? true) === true) {
            $reason = (string) ($plan['metadata']['reason'] ?? 'sermon_composition_review');
            $this->error("The selected structure requires review ({$reason}); resolve it before re-cutting.");
            $this->suggestHeldSermons($processingLog);

            return self::FAILURE;
        }

        if ($heldSection instanceof ServiceSection && ($plan['metadata']['sermon_section_id'] ?? null) !== $heldSection->id) {
            $this->error("The plan does not cut from the named held section {$heldSection->id}.");

            return self::FAILURE;
        }

        if ($heldSection instanceof ServiceSection) {
            $this->line("Held:     section {$heldSection->id} stays held; this re-cut is authorised for its current span only.");
        }

        $sourcePath = (string) $processingLog->source_file_path;
        $sourceExists = $sourcePath !== '' && (bool) $inspect(
            fn (): bool => Storage::disk((string) config('media-processing.storage.temp_disk'))->exists($sourcePath)
        );

        if (! $sourceExists) {
            // CleanupTemporaryFiles deletes source_file_path when a run completes,
            // so a finished run usually has nothing left to cut from. The media has
            // to be restaged before this can run — for a historic import that is
            // `historic-import:restage-source`.
            //
            // It is deliberately NOT `sermons:import-historic-videos --force`: the
            // batch command rejects `--force` on a definitive manifest run by design,
            // and the importer's resume-completed short-circuit runs before the force
            // check anyway, so that route reports success while doing nothing.
            $this->error("The source media for this run is gone ({$sourcePath}), so it cannot be re-cut.");
            $this->line('Restage it first with `historic-import:restage-source`, which accepts a candidate only when it matches the hash the run recorded.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing dispatched.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Re-cut and republish this sermon?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        if ($heldSection instanceof ServiceSection) {
            $processingLog->authoriseHeldSermonSpan($heldSection);
        }

        $result = $orchestrator->reExtract($processingLog);

        if (! $result->success) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info("Re-extraction dispatched for {$processingId}.");

        return self::SUCCESS;
    }

    private function heldSermonOf(MediaProcessingLog $processingLog, int $sectionId): ?ServiceSection
    {
        $section = $processingLog->serviceSections()
            ->whereKey($sectionId)
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->first();

        if (! $section instanceof ServiceSection) {
            return null;
        }

        return HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? []) ? $section : null;
    }

    private function suggestHeldSermons(MediaProcessingLog $processingLog): void
    {
        $processingLog->serviceSections()
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->orderBy('section_order')
            ->get()
            ->filter(fn (ServiceSection $section): bool => HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? []))
            ->each(fn (ServiceSection $section) => $this->line(sprintf(
                'Section %d is a content-held sermon (%.1fs - %.1fs). If its span is right, re-run with --held-section=%d; the hold stays.',
                $section->id,
                (float) $section->start_time,
                (float) $section->end_time,
                $section->id,
            )));
    }
}
