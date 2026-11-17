<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportNestedJob;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\AutomaticPublicationJudgement;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Re-ask preparation's judgement under a row lock before publishing or approving a pending cut. */
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

            if (in_array($section->publication_status, [ServiceSectionPublicationStatus::Published, ServiceSectionPublicationStatus::Approved], true)) {
                return;
            }

            $handler = $handlerFactory->forSection($section);
            if ($handler === null) {
                throw new \RuntimeException('No publication handler registered for section type: '.$section->section_type->value);
            }

            $withheld = app(AutomaticPublicationJudgement::class)->reasonToWithhold($section, $handler);

            if ($section->isDirty()) {
                $section->save();
            }

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

            if ($section->publication_status === ServiceSectionPublicationStatus::PendingApproval) {
                $error = app(ApproveSectionForPublication::class)->execute($section, [
                    'source' => 'automatic',
                    'action' => 'advance_clean_pending_section',
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => null,
                ]);
                if ($error !== null) {
                    throw new \RuntimeException($error);
                }

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
