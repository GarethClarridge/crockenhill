<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ServiceSeeder extends Seeder
{
  public function run()
  {
    // Create services for recent Sundays
    $date = Carbon::now()->startOfWeek()->previous(Carbon::SUNDAY);

    for ($i = 0; $i < 12; $i++) {
      $serviceDate = $date->copy()->subWeeks($i);

      // Morning service
      Service::create([
        'date' => $serviceDate->format('Y-m-d'),
        'type' => 'morning',
        'video' => 'videos/' . $serviceDate->format('Y-m-d') . '_morning_service.mp4',
        'audio' => 'audio/' . $serviceDate->format('Y-m-d') . '_morning_service.mp3',
      ]);

      // Evening service
      Service::create([
        'date' => $serviceDate->format('Y-m-d'),
        'type' => 'evening',
        'video' => 'videos/' . $serviceDate->format('Y-m-d') . '_evening_service.mp4',
        'audio' => 'audio/' . $serviceDate->format('Y-m-d') . '_evening_service.mp3',
      ]);
    }

    Service::factory(10)->create();
  }
}
