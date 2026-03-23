<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ServiceSectionSyncService
{
    /**
     * @param  array<int, array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence?: float|null,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }>  $classifiedSections
     */
    public function sync(MediaProcessingLog $processingLog, array $classifiedSections): void
    {
        DB::transaction(function () use ($processingLog, $classifiedSections): void {
            $existingByOrder = ServiceSection::query()
                ->where('media_processing_log_id', $processingLog->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('section_order');

            $incomingOrders = [];

            foreach ($classifiedSections as $sectionData) {
                $incomingOrders[] = $sectionData['section_order'];

                $payload = [
                    'media_processing_log_id' => $processingLog->id,
                    'church_service_item_id' => $sectionData['church_service_item_id'],
                    'section_type' => $sectionData['section_type'],
                    'section_order' => $sectionData['section_order'],
                    'title' => $sectionData['title'],
                    'start_time' => $sectionData['start_time'],
                    'end_time' => $sectionData['end_time'],
                    'duration' => $sectionData['duration'],
                    'confidence' => $this->resolveConfidence($sectionData),
                    'status' => $sectionData['status'],
                    'needs_manual_review' => $sectionData['needs_manual_review'],
                    'source_segment_ids' => $sectionData['source_segment_ids'],
                    'metadata' => $sectionData['metadata'],
                ];

                $existing = $existingByOrder->get($sectionData['section_order']);

                if ($existing instanceof ServiceSection) {
                    $signatureChanged = $this->hasMaterialSignatureChange($existing, $payload);

                    if ($signatureChanged) {
                        $this->cleanupExtractedAssets($existing);

                        $payload = array_merge(
                            $payload,
                            $this->supersededReplacementPayload($existing, $payload)
                        );
                    } else {
                        $payload['metadata'] = $this->mergeExistingMetadata($existing, $payload['metadata']);
                    }

                    $existing->fill($payload);
                    $existing->save();

                    continue;
                }

                ServiceSection::query()->create(array_merge(
                    $payload,
                    [
                        'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
                        'published_sermon_id' => null,
                        'published_at' => null,
                        'extracted_video_path' => null,
                        'extracted_audio_path' => null,
                        'extracted_at' => null,
                        'unpublished_expires_at' => null,
                    ]
                ));
            }

            $staleSections = ServiceSection::query()
                ->where('media_processing_log_id', $processingLog->id)
                ->when(
                    $incomingOrders !== [],
                    fn ($query) => $query->whereNotIn('section_order', $incomingOrders),
                    fn ($query) => $query
                )
                ->lockForUpdate()
                ->get();

            foreach ($staleSections as $staleSection) {
                $this->cleanupExtractedAssets($staleSection);
                $this->detachPublishedLinkBeforeStaleDelete($staleSection);
                $staleSection->delete();
            }
        });
    }

    /**
     * @param  array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     confidence: float|null,
     *     metadata: array<string, mixed>
     * }  $incomingPayload
     */
    private function hasMaterialSignatureChange(ServiceSection $existing, array $incomingPayload): bool
    {
        return $this->buildSignatureFromExisting($existing) !== $this->buildSignatureFromPayload($incomingPayload);
    }

    /**
     * @param  array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     confidence: float|null,
     *     metadata: array<string, mixed>
     * }  $incomingPayload
     * @return array<string, mixed>
     */
    private function supersededReplacementPayload(ServiceSection $existing, array $incomingPayload): array
    {
        $now = CarbonImmutable::now();
        $previousSignature = $this->buildSignatureFromExisting($existing);
        $nextSignature = $this->buildSignatureFromPayload($incomingPayload);
        $supersedeMetadata = [
            'at' => $now->toIso8601String(),
            'previous_signature' => $previousSignature,
            'next_signature' => $nextSignature,
            'previous_published_sermon_id' => $existing->published_sermon_id,
        ];

        $isPublishableType = $this->isPublishableType($incomingPayload['section_type']);

        if ($existing->published_sermon_id !== null) {
            Log::warning('Published service section superseded by classification refresh', [
                'service_section_id' => $existing->id,
                'processing_log_id' => $existing->media_processing_log_id,
                'published_sermon_id' => $existing->published_sermon_id,
            ]);
        }

        return [
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'published_sermon_id' => null,
            'published_at' => null,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'unpublished_expires_at' => null,
            'metadata' => array_merge(
                $incomingPayload['metadata'],
                [
                    'superseded' => $supersedeMetadata,
                    'publishable_type_after_supersede' => $isPublishableType,
                ]
            ),
        ];
    }

    private function detachPublishedLinkBeforeStaleDelete(ServiceSection $section): void
    {
        if ($section->published_sermon_id === null) {
            return;
        }

        Log::warning('Published service section removed as stale after classification refresh', [
            'service_section_id' => $section->id,
            'processing_log_id' => $section->media_processing_log_id,
            'published_sermon_id' => $section->published_sermon_id,
            'signature' => $this->buildSignatureFromExisting($section),
        ]);
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float
     * }
     */
    private function buildSignatureFromExisting(ServiceSection $section): array
    {
        return [
            'church_service_item_id' => $section->church_service_item_id,
            'section_type' => $section->section_type->value,
            'title' => $section->title,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
        ];
    }

    /**
     * @param  array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     confidence: float|null,
     *     metadata: array<string, mixed>
     * }  $payload
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float
     * }
     */
    private function buildSignatureFromPayload(array $payload): array
    {
        return [
            'church_service_item_id' => $payload['church_service_item_id'],
            'section_type' => $payload['section_type'],
            'title' => $payload['title'],
            'start_time' => (float) $payload['start_time'],
            'end_time' => (float) $payload['end_time'],
        ];
    }

    /**
     * @param  array{
     *     confidence?: float|null,
     *     metadata: array<string, mixed>
     * }  $sectionData
     */
    private function resolveConfidence(array $sectionData): float
    {
        $confidence = $sectionData['confidence'] ?? null;

        return ServiceSectionConfidence::resolve(
            is_numeric($confidence) ? (float) $confidence : null,
            $sectionData['metadata']
        );
    }

    private function isPublishableType(string $sectionType): bool
    {
        $publishableTypes = config('media-processing.section_publishing.publishable_types', ['childrens_talk']);

        return is_array($publishableTypes) && in_array($sectionType, $publishableTypes, true);
    }

    /**
     * @param  array<string, mixed>  $incomingMetadata
     * @return array<string, mixed>
     */
    private function mergeExistingMetadata(ServiceSection $existing, array $incomingMetadata): array
    {
        $existingMetadata = $existing->metadataData()->toArray();

        return array_merge($existingMetadata, $incomingMetadata);
    }

    private function cleanupExtractedAssets(ServiceSection $section): void
    {
        $this->deleteResolvedPath($section, $section->extracted_video_path);
        $this->deleteResolvedPath($section, $section->extracted_audio_path);
    }

    private function deleteResolvedPath(ServiceSection $section, ?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $disk = $section->extractedAssetDisk($path);

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $throwable) {
            Log::warning('Failed to clean up extracted section asset on disk', [
                'disk' => $disk,
                'path' => $path,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
