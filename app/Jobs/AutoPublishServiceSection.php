<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\SectionPublicationHandler;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportNestedJob;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publish one section the preparation step judged eligible.
 *
 * **Eligibility is re-asked here, not inherited from the dispatch.**
 * {@see PrepareSectionPublicationCandidates} decides what may publish and then
 * queues this, so between the two there is a window in which a section can
 * acquire a hold, lose its handler eligibility, come to require approval, or
 * have its owning run retired. Nothing in the queue payload notices, and the
 * publication transition table only closes half the door: it refuses
 * `pending_approval` and `rejected`, but `not_applicable` and `approved` may
 * both become `published`, and those are the states a section sits in while it
 * waits.
 *
 * That window is not hypothetical. The 2026-09-09 repetition screen raised holds
 * on 194 sections, 157 of them `not_applicable`; a job queued a moment earlier
 * would have published every one of them over its own hold.
 *
 * So this fails closed on anything that changed, leaving the section for review
 * rather than publishing it. A section that is genuinely still eligible is
 * unaffected — the questions are the same ones preparation asked, asked again
 * under the row lock that makes the answer current.
 */
class AutoPublishServiceSection implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly int $serviceSectionId
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('auto-publish-service-section-'.$this->serviceSectionId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        SectionPublicationHandlerFactory $handlerFactory,
    ): void {
        $nestedJob = HistoricImportNestedJob::query()
            ->where('job_key', 'auto-publish-section-'.$this->serviceSectionId)
            ->first();

        if ($nestedJob instanceof HistoricImportNestedJob) {
            $nestedJob->state = 'running';
            $nestedJob->attempts++;
            $nestedJob->save();
        }

        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            if ($nestedJob instanceof HistoricImportNestedJob) {
                throw new \RuntimeException('Section publishing was disabled after historic nested work was admitted.');
            }

            return;
        }

        DB::transaction(function () use ($handlerFactory): void {
            $section = ServiceSection::query()
                ->with(['processingLog', 'churchServiceItem'])
                ->lockForUpdate()
                ->find($this->serviceSectionId);

            if (! $section instanceof ServiceSection) {
                return;
            }

            if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
                return;
            }

            $handler = $handlerFactory->forSection($section);
            if ($handler === null) {
                throw new \RuntimeException('No publication handler registered for section type: '.$section->section_type->value);
            }

            $withheld = $this->reasonToWithhold($section, $handler);

            if ($withheld !== null) {
                // Deliberately not an exception. The section is not broken and
                // the job did not fail; the answer to "may this publish?" simply
                // changed after the question was asked, and a failed job would
                // retry into the same answer three times and then alarm.
                Log::info('Withheld an auto-publish whose eligibility lapsed after it was queued', [
                    'service_section_id' => $section->id,
                    'publication_status' => $section->publication_status->value,
                    'reason' => $withheld,
                ]);

                return;
            }

            $handler->publish($section);
        });

        if ($nestedJob instanceof HistoricImportNestedJob) {
            $nestedJob->state = 'completed';
            $nestedJob->settled_at = now();
            $nestedJob->save();
        }
    }

    /**
     * Why this section may no longer publish, or null when it still may.
     *
     * Three facts that can lapse between dispatch and execution, and only facts:
     * the owning run's retirement — a retired run's result is withdrawn, so
     * publishing from it would republish a conclusion the import has already
     * taken back — the review hold, and the handler's own eligibility. Both
     * `isEligible()` implementations are pure reads, so asking again here costs
     * nothing and changes nothing.
     *
     * `requiresApproval()` is deliberately **not** re-asked, though the 2026-09-09
     * review lists it. It is not a predicate: the song handler's version runs the
     * publication review policy and writes the boundary evidence and review
     * reasons back onto the section. It is a decide-and-record step owned by
     * {@see PrepareSectionPublicationCandidates}, which calls it only after
     * `afterExtraction()` has supplied what the assessment reads — so re-deriving
     * it here answers from inputs this job does not own, and a regression proved
     * it: the guard withheld a section that publishes correctly today.
     *
     * Nothing is lost by leaving it out. A section that came to require approval
     * during preparation was transitioned to `pending_approval`, and
     * {@see \App\Services\ChurchService\ServiceSectionPublicationTransitionService}
     * already refuses `pending_approval` to `published`. The states this guard
     * has to cover are the ones that table permits — `not_applicable` and
     * `approved` — and for those the hold is the durable expression of "a
     * reviewer must look".
     */
    private function reasonToWithhold(ServiceSection $section, SectionPublicationHandler $handler): ?string
    {
        // The relation is not guarded: `media_processing_log_id` is non-nullable
        // and the rest of the codebase reads it the same way.
        if ($section->processingLog->isRetired()) {
            return 'the owning run has been retired';
        }

        if ($section->needs_manual_review) {
            return 'the section has been held for manual review';
        }

        if (! $handler->isEligible($section)) {
            return 'the section is no longer eligible for publication';
        }

        return null;
    }

    public function failed(\Throwable $exception): void
    {
        $nestedJob = HistoricImportNestedJob::query()
            ->where('job_key', 'auto-publish-section-'.$this->serviceSectionId)
            ->first();

        if ($nestedJob instanceof HistoricImportNestedJob) {
            $nestedJob->state = 'failed';
            $nestedJob->error_fingerprint = hash('sha256', $exception::class."\0".$exception->getMessage());
            $nestedJob->settled_at = now();
            $nestedJob->save();
        }

        Log::error('AutoPublishServiceSection job failed', [
            'service_section_id' => $this->serviceSectionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
