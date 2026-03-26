<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonCreationOptions;
use App\Enums\MediaType;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Services\MediaProcessingIdentityResolver;
use App\Services\SermonCreationService;
use App\Services\ServiceSectionPublicationTransitionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
                ->expireAfter(300),
        ];
    }

    public function handle(
        SermonCreationService $sermonCreationService,
        MediaProcessingIdentityResolver $identityResolver,
        ServiceSectionPublicationTransitionService $publicationTransitions
    ): void {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            return;
        }

        DB::transaction(function () use (
            $identityResolver,
            $publicationTransitions,
            $sermonCreationService
        ): void {
            $section = ServiceSection::query()
                ->with('processingLog')
                ->lockForUpdate()
                ->find($this->serviceSectionId);

            if (! $section instanceof ServiceSection) {
                return;
            }

            if (
                $section->publication_status === ServiceSectionPublicationStatus::PUBLISHED
                && $section->published_sermon_id !== null
            ) {
                return;
            }

            if (
                $section->publication_status !== ServiceSectionPublicationStatus::APPROVED
                || $section->published_sermon_id !== null
            ) {
                return;
            }

            $processingLog = $section->processingLog;
            if ($processingLog->processing_type !== MediaType::Livestream) {
                throw new \RuntimeException('Only livestream sections can be published');
            }

            $videoPath = $section->extracted_video_path;
            $audioPath = $section->extracted_audio_path;
            if (! is_string($videoPath) || $videoPath === '') {
                throw new \RuntimeException('Section video path missing for approved publication');
            }
            if (! is_string($audioPath) || $audioPath === '') {
                throw new \RuntimeException('Section audio path missing for approved publication');
            }

            if (! Storage::disk($section->extractedAssetDisk($videoPath))->exists($videoPath)) {
                throw new \RuntimeException('Section video file is missing for approved publication');
            }
            if (! Storage::disk($section->extractedAssetDisk($audioPath))->exists($audioPath)) {
                throw new \RuntimeException('Section audio file is missing for approved publication');
            }

            $identity = $identityResolver->resolve($processingLog);
            if ($identity === null) {
                throw new \RuntimeException('Unable to resolve processing identity for section publication');
            }

            $sectionMetadata = $section->metadata?->toArray() ?? [];
            $publicationMetadata = is_array($sectionMetadata['publication'] ?? null)
                ? $sectionMetadata['publication']
                : [];
            $approvedSignature = $publicationMetadata['approved_signature'] ?? null;
            if (! is_string($approvedSignature) || $approvedSignature === '') {
                throw new \RuntimeException('Section approval signature is missing; re-approve before publishing');
            }

            $currentSignature = $section->classificationSignature();
            if (! hash_equals($approvedSignature, $currentSignature)) {
                throw new \RuntimeException('Section classification changed since approval; re-approve before publishing');
            }

            if (! $section->hasResolvedChildrensTalkSpeaker()) {
                throw new \RuntimeException("Children's talk speaker must be reviewed before publication");
            }

            $section->extracted_video_path = $this->promoteExtractedAsset(
                $section,
                $videoPath,
                'sermons/sections/'.$section->id.'/video.'.pathinfo($videoPath, PATHINFO_EXTENSION),
            );
            $section->extracted_audio_path = $this->promoteExtractedAsset(
                $section,
                $audioPath,
                'sermons/audio/'.basename($audioPath),
            );

            if (! $publicationTransitions->transition($section, ServiceSectionPublicationStatus::PUBLISHED)) {
                throw new \RuntimeException('Invalid state transition when publishing approved section');
            }

            $options = SermonCreationOptions::fromServiceSection(
                $section,
                $processingLog,
                $identity['date'],
                $identity['service']
            );

            $sermon = $sermonCreationService->createSermon($processingLog, $options);

            $section->published_sermon_id = $sermon->id;
            $section->published_at = now();
            $section->unpublished_expires_at = null;
            $section->save();
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PublishApprovedServiceSection job failed', [
            'service_section_id' => $this->serviceSectionId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function promoteExtractedAsset(
        ServiceSection $section,
        string $sourcePath,
        string $targetPath,
    ): string {
        $sourceDisk = $section->extractedAssetDisk($sourcePath);
        $targetDisk = (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));

        if ($sourceDisk === $targetDisk && $sourcePath === $targetPath) {
            return $sourcePath;
        }

        $sourceStream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($sourceStream)) {
            throw new \RuntimeException('Unable to read approved section asset for publication');
        }

        try {
            $written = Storage::disk($targetDisk)->put($targetPath, $sourceStream);
        } finally {
            fclose($sourceStream);
        }

        if ($written !== true || ! Storage::disk($targetDisk)->exists($targetPath)) {
            throw new \RuntimeException('Unable to publish approved section asset to the public sermon disk');
        }

        if ($sourceDisk !== $targetDisk || $sourcePath !== $targetPath) {
            Storage::disk($sourceDisk)->delete($sourcePath);
        }

        return $targetPath;
    }
}
