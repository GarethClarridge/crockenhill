<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            UserSeeder::class,
            PreacherSeeder::class,
            SermonSeeder::class,
            PageSeeder::class,
            MeetingSeeder::class,
            TranscriptSeeder::class,
        ]);
    }
}
