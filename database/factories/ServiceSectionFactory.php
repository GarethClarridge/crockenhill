<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceSection>
 */
class ServiceSectionFactory extends Factory
{
    protected $model = ServiceSection::class;

    public function configure(): static
    {
        $pendingSectionOrders = [];

        return $this->afterMaking(function (ServiceSection $section) use (&$pendingSectionOrders): void {
            $processingLogId = $section->media_processing_log_id;

            if (is_int($processingLogId)) {
                $requestedOrder = is_int($section->section_order) && $section->section_order > 0
                    ? $section->section_order
                    : 1;
                $occupiedOrders = $pendingSectionOrders[$processingLogId] ?? [];

                if (
                    isset($occupiedOrders[$requestedOrder])
                    || ServiceSection::query()
                        ->where('media_processing_log_id', $processingLogId)
                        ->where('section_order', $requestedOrder)
                        ->exists()
                ) {
                    $maxPendingOrder = $occupiedOrders === [] ? 0 : max(array_keys($occupiedOrders));
                    $maxPersistedOrder = (int) ServiceSection::query()
                        ->where('media_processing_log_id', $processingLogId)
                        ->max('section_order');

                    $requestedOrder = max($maxPendingOrder, $maxPersistedOrder) + 1;
                    $section->section_order = $requestedOrder;
                }

                $pendingSectionOrders[$processingLogId][$requestedOrder] = true;
            }

            if (is_numeric($section->start_time) && is_numeric($section->end_time)) {
                $section->duration = max(0.0, (float) $section->end_time - (float) $section->start_time);
            }

            if (
                is_string($section->extracted_video_path)
                && $section->extracted_video_path !== ''
                && is_string($section->extracted_audio_path)
                && $section->extracted_audio_path !== ''
            ) {
                $section->extracted_at ??= now();
            }

            if ($section->published_sermon_id !== null || $section->published_at !== null) {
                $section->publication_status = ServiceSectionPublicationStatus::PUBLISHED;
            }

            if (
                in_array($section->publication_status, [
                    ServiceSectionPublicationStatus::APPROVED,
                    ServiceSectionPublicationStatus::PUBLISHED,
                ], true)
            ) {
                $section->extracted_video_path ??= 'sermons/sections/'.($section->id ?? 'pending').'/video.mp4';
                $section->extracted_audio_path ??= 'sermons/audio/section-'.($section->id ?? 'pending').'.mp3';
                $section->extracted_at ??= now();
            }

            if ($section->publication_status === ServiceSectionPublicationStatus::PUBLISHED) {
                $section->published_sermon_id ??= Sermon::factory()->create()->id;
                $section->published_at ??= now();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startTime = (float) $this->faker->numberBetween(0, 3000);
        $duration = (float) $this->faker->numberBetween(30, 900);
        $endTime = $startTime + $duration;

        return [
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream(),
            'church_service_item_id' => ChurchServiceItem::factory(),
            'section_type' => $this->faker->randomElement(ServiceSectionType::cases()),
            'section_order' => $this->faker->unique()->numberBetween(1, 100),
            'title' => $this->faker->optional()->sentence(3),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
            'confidence' => 0.9,
            'status' => ServiceSectionStatus::IDENTIFIED,
            'needs_manual_review' => false,
            'source_segment_ids' => [$this->faker->numberBetween(1, 20)],
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
            ],
            'song_match_type' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE,
            'published_sermon_id' => null,
            'published_at' => null,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'unpublished_expires_at' => null,
        ];
    }
}
