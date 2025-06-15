<?php

namespace Database\Seeders;

use App\Models\PlayDate;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class PlayDateSeeder extends Seeder
{
  public function run()
  {
    // Create play dates for recent Sundays
    $recentSundays = [];
    $date = Carbon::now()->startOfWeek()->previous(Carbon::SUNDAY);

    for ($i = 0; $i < 8; $i++) {
      $recentSundays[] = $date->copy()->subWeeks($i);
    }

    foreach ($recentSundays as $sunday) {
      // Morning service songs
      PlayDate::create([
        'song_id' => rand(1, 10),
        'date' => $sunday->format('Y-m-d'),
        'time' => 'a',
      ]);

      PlayDate::create([
        'song_id' => rand(1, 10),
        'date' => $sunday->format('Y-m-d'),
        'time' => 'a',
      ]);

      // Evening service songs
      PlayDate::create([
        'song_id' => rand(1, 10),
        'date' => $sunday->format('Y-m-d'),
        'time' => 'p',
      ]);
    }

    PlayDate::factory(20)->create();
  }
}
