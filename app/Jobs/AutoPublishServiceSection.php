<?php

declare(strict_types=1);

namespace App\Jobs;

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
                ->expireAfter(300),
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
