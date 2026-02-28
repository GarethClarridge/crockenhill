<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\DB;

class ServiceSectionSyncService
{
    /**
     * @param  array<int, array{
     *     church_service_item_id: int,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
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
                    'status' => $sectionData['status'],
                    'needs_manual_review' => $sectionData['needs_manual_review'],
                    'source_segment_ids' => $sectionData['source_segment_ids'],
                    'metadata' => $sectionData['metadata'],
                ];

                $existing = $existingByOrder->get($sectionData['section_order']);

                if ($existing instanceof ServiceSection) {
                    $existing->fill($payload);
                    $existing->save();

                    continue;
                }

                ServiceSection::query()->create($payload);
            }

            ServiceSection::query()
                ->where('media_processing_log_id', $processingLog->id)
                ->when(
                    $incomingOrders !== [],
                    fn ($query) => $query->whereNotIn('section_order', $incomingOrders),
                    fn ($query) => $query
                )
                ->delete();
        });
    }
}
