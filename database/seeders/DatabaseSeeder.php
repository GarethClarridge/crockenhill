<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
  public function run()
  {
    $this->call([
      UserSeeder::class,
      SongSeeder::class,
      SermonSeeder::class,
      PageSeeder::class,
      MeetingSeeder::class,
      DocumentSeeder::class,
      ScriptureReferenceSeeder::class,
      PlayDateSeeder::class,
      ServiceSeeder::class,
    ]);
  }
}
