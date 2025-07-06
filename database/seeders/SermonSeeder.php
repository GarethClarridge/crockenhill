<?php

namespace Database\Seeders;

use App\Models\Sermon;
use Illuminate\Database\Seeder;

class SermonSeeder extends Seeder
{
    public function run()
    {
        // Create some specific sermons
        $sermons = [
            [
                'date' => '2024-12-15',
                'service' => 'morning',
                'title' => 'The Birth of Our Saviour',
                'slug' => 'the-birth-of-our-saviour',
                'reference' => 'Luke 2:1-20',
                'preacher' => 'Mark Drury',
                'series' => 'Christmas Messages',
                'filename' => 'christmas-2024-morning.mp3',
                'points' => '1. The historical reality\n2. The divine purpose\n3. The eternal significance',
            ],
            [
                'date' => '2024-12-08',
                'service' => 'morning',
                'title' => 'Walking in the Light',
                'slug' => 'walking-in-the-light',
                'reference' => '1 John 1:5-10',
                'preacher' => 'Mark Drury',
                'series' => '1 John',
                'filename' => '1-john-walking-in-light.mp3',
                'points' => '1. God is light\n2. Fellowship with God\n3. Confession and forgiveness',
            ],
        ];

        foreach ($sermons as $sermon) {
            Sermon::create($sermon);
        }

        // Create additional random sermons
        Sermon::factory(25)->create();
    }
}
