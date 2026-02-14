<?php

namespace Database\Seeders;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
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
                'service' => SermonService::MORNING->value,
                'title' => 'The Birth of Our Saviour',
                'slug' => 'the-birth-of-our-saviour',
                'reference' => 'Luke 2:1-20',
                'preacher' => 'Mark Drury',
                'preacher_id' => $markDrury?->id,
                'preacher_source' => 'manual',
                'series' => 'Christmas Messages',
                'audio_file_path' => 'christmas-2024-morning.mp3',
                'points' => '1. The historical reality\n2. The divine purpose\n3. The eternal significance',
            ],
            [
                'date' => '2024-12-08',
                'service' => SermonService::MORNING->value,
                'title' => 'Walking in the Light',
                'slug' => 'walking-in-the-light',
                'reference' => '1 John 1:5-10',
                'preacher' => 'Mark Drury',
                'preacher_id' => $markDrury?->id,
                'preacher_source' => 'manual',
                'series' => '1 John',
                'audio_file_path' => '1-john-walking-in-light.mp3',
                'points' => '1. God is light\n2. Fellowship with God\n3. Confession and forgiveness',
            ],
            [
                'date' => '2024-12-01',
                'service' => SermonService::OTHER->value,
                'title' => 'Special Service Example',
                'slug' => 'special-service-example',
                'reference' => 'Psalm 100',
                'preacher' => 'Visiting Speaker',
                'preacher_id' => $visitingSpeaker?->id,
                'preacher_source' => 'default',
                'needs_preacher_review' => true,
                'series' => 'Special Events',
                'audio_file_path' => 'special-2024-other.mp3',
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
    }
}
