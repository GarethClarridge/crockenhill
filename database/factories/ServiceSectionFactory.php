<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceSection>
 */
class ServiceSectionFactory extends Factory
{
    protected $model = ServiceSection::class;

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
