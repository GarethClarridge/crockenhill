<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishApprovedServiceSection implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        private int $serviceSectionId
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('publish-service-section-'.$this->serviceSectionId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        SectionPublicationHandlerFactory $handlerFactory,
    ): void {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            return;
        }

        DB::transaction(function () use ($handlerFactory): void {
            $section = ServiceSection::query()
                ->with('processingLog')
                ->lockForUpdate()
                ->find($this->serviceSectionId);

            if (! $section instanceof ServiceSection) {
                return;
            }

            if (
                $section->publication_status === ServiceSectionPublicationStatus::Published
                && $section->published_sermon_id !== null
            ) {
                return;
            }

            if (
                $section->publication_status !== ServiceSectionPublicationStatus::Approved
                || $section->published_sermon_id !== null
            ) {
                return;
            }

            $processingLog = $section->processingLog;
            if ($processingLog->processing_type !== MediaType::Livestream) {
                throw new \RuntimeException('Only livestream sections can be published');
            }

            $handler = $handlerFactory->forSection($section);
            if ($handler === null) {
                throw new \RuntimeException('No publication handler registered for section type: '.$section->section_type->value);
            }

            $handler->publish($section);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PublishApprovedServiceSection job failed', [
            'service_section_id' => $this->serviceSectionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
