<?php

namespace Database\Seeders;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Database\Seeder;

class SermonSeeder extends Seeder
{
    public function run(): void
    {
        $markDrury = Preacher::where('slug', 'mark-drury')->first();
        $visitingSpeaker = Preacher::where('slug', 'visiting-speaker')->first();

        // Create some specific sermons
        $sermons = [
            [
                'date' => '2024-12-15',
                'service' => SermonService::Morning->value,
                'title' => 'The Birth of Our Saviour',
                'slug' => 'the-birth-of-our-saviour',
                'reference' => 'Luke 2:1-20',
                'preacher' => 'Mark Drury',
                'preacher_id' => $markDrury?->id,
                'preacher_source' => 'manual',
                'series' => 'Christmas Messages',
                'audio_file_path' => null,
                'points' => '1. The historical reality\n2. The divine purpose\n3. The eternal significance',
            ],
            [
                'date' => '2024-12-08',
                'service' => SermonService::Morning->value,
                'title' => 'Walking in the Light',
                'slug' => 'walking-in-the-light',
                'reference' => '1 John 1:5-10',
                'preacher' => 'Mark Drury',
                'preacher_id' => $markDrury?->id,
                'preacher_source' => 'manual',
                'series' => '1 John',
                'audio_file_path' => null,
                'points' => '1. God is light\n2. Fellowship with God\n3. Confession and forgiveness',
            ],
            [
                'date' => '2024-12-01',
                'service' => SermonService::Other->value,
                'title' => 'Special Service Example',
                'slug' => 'special-service-example',
                'reference' => 'Psalm 100',
                'preacher' => 'Visiting Speaker',
                'preacher_id' => $visitingSpeaker?->id,
                'preacher_source' => 'default',
                'needs_preacher_review' => true,
                'series' => 'Special Events',
                'audio_file_path' => null,
                'points' => '1. Praise\n2. Thanksgiving',
            ],
        ];

        foreach ($sermons as $sermon) {
            Sermon::updateOrCreate(
                ['slug' => $sermon['slug']],
                $sermon
            );
        }

        // Keep a stable baseline of additional random sermons.
        $targetRandomSermons = 25;
        $seededSlugs = array_column($sermons, 'slug');
        $existingRandomSermons = Sermon::whereNotIn('slug', $seededSlugs)->count();

        if ($existingRandomSermons < $targetRandomSermons) {
            Sermon::factory($targetRandomSermons - $existingRandomSermons)->create();
        }

        $this->seedChildrensTalks($markDrury);
        $this->seedSermonWithReading($markDrury);
    }

    private function seedChildrensTalks(?Preacher $preacher): void
    {
        $talks = [
            [
                'date' => '2024-12-15',
                'service' => SermonService::Morning->value,
                'title' => "Who is Jesus? (Children's Talk)",
                'slug' => 'childrens-talk-who-is-jesus',
                'reference' => 'Mark 1:1-11',
                'preacher' => $preacher?->name ?? 'Mark Drury',
                'preacher_id' => $preacher?->id,
                'preacher_source' => 'manual',
                'audio_file_path' => null,
                'content_type' => SermonContentType::ChildrensTalk->value,
            ],
            [
                'date' => '2024-12-08',
                'service' => SermonService::Morning->value,
                'title' => "God Made the World (Children's Talk)",
                'slug' => 'childrens-talk-god-made-the-world',
                'reference' => 'Genesis 1:1',
                'preacher' => $preacher?->name ?? 'Mark Drury',
                'preacher_id' => $preacher?->id,
                'preacher_source' => 'manual',
                'audio_file_path' => null,
                'content_type' => SermonContentType::ChildrensTalk->value,
            ],
            [
                'date' => '2024-12-01',
                'service' => SermonService::Morning->value,
                'title' => "The Good Shepherd (Children's Talk)",
                'slug' => 'childrens-talk-the-good-shepherd',
                'reference' => 'John 10:11-18',
                'preacher' => $preacher?->name ?? 'Mark Drury',
                'preacher_id' => $preacher?->id,
                'preacher_source' => 'manual',
                'audio_file_path' => null,
                'content_type' => SermonContentType::ChildrensTalk->value,
            ],
        ];

        foreach ($talks as $talk) {
            Sermon::updateOrCreate(['slug' => $talk['slug']], $talk);
        }
    }

    private function seedSermonWithReading(?Preacher $preacher): void
    {
        // Only create the processing context if it doesn't already exist.
        $processingLog = MediaProcessingLog::where('processing_id', 'seed-prodigal-son-processing')->first();

        if (! $processingLog) {
            $audioPath = 'sermons/seed/2024-11-24.mp3';
            $exists = \Illuminate\Support\Facades\Storage::disk('public')->exists($audioPath);

            $processingLog = MediaProcessingLog::create([
                'processing_id' => 'seed-prodigal-son-processing',
                'processing_type' => MediaType::Livestream,
                'original_filename' => '2024-11-24-morning-service.mp4',
                'status' => $exists ? ProcessingStatus::Completed : ProcessingStatus::Failed,
                'current_step' => $exists ? 'completed' : 'failed',
                'sermon_id' => null,
                'audio_file_path' => $exists ? $audioPath : null,
            ]);
        }

        $exists = $processingLog->audio_file_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($processingLog->audio_file_path);

        $sermon = Sermon::updateOrCreate(
            ['slug' => 'the-prodigal-son'],
            [
                'date' => '2024-11-24',
                'service' => SermonService::Morning->value,
                'title' => 'The Prodigal Son',
                'reference' => 'Luke 15:11-32',
                'preacher' => $preacher?->name ?? 'Mark Drury',
                'preacher_id' => $preacher?->id,
                'preacher_source' => 'manual',
                'series' => 'Parables of Jesus',
                'audio_file_path' => $exists ? $processingLog->audio_file_path : null,
                'points' => '1. The son who wandered\n2. The father who waited\n3. The grace that restores',
                'livestream_processing_id' => 'seed-prodigal-son-processing',
            ]
        );

        $processingLog->update(['sermon_id' => $sermon->id]);

        $churchService = ChurchService::where('date', '2024-11-24')->where('service', SermonService::Morning->value)->first();

        if (! $churchService) {
            $churchService = ChurchService::create([
                'date' => '2024-11-24',
                'service' => SermonService::Morning->value,
                'source' => 'openlp',
                'original_filename' => '2024-11-24 AM.osz',
                'needs_review' => false,
                'import_metadata' => ['confidence_score' => 1.0, 'warnings' => []],
            ]);
        }

        $readingItem = ChurchServiceItem::where('church_service_id', $churchService->id)->where('position', 2)->first();

        if (! $readingItem) {
            $readingItem = ChurchServiceItem::create([
                'church_service_id' => $churchService->id,
                'position' => 2,
                'type' => 'bibles',
                'source' => 'openlp',
                'title' => 'Luke 15:1-10',
            ]);
        }

        if (ServiceSection::where('media_processing_log_id', $processingLog->id)->where('section_order', 2)->doesntExist()) {
            ServiceSection::create([
                'media_processing_log_id' => $processingLog->id,
                'church_service_item_id' => $readingItem->id,
                'section_type' => ServiceSectionType::BibleReading->value,
                'section_order' => 2,
                'title' => 'Luke 15:1-10',
                'start_time' => 720.0,
                'end_time' => 900.0,
                'duration' => 180.0,
                'confidence' => 0.95,
                'status' => 'identified',
                'needs_manual_review' => false,
                'source_segment_ids' => [3],
                'metadata' => [
                    'reading_reference' => 'Luke 15:1-10',
                    'classification_mode' => 'openlp_aligned',
                    'confidence_level' => 'high',
                ],
                'publication_status' => 'not_applicable',
            ]);
        }
    }
}
