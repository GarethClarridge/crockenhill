<?php

namespace Database\Seeders;

use App\Models\Meeting;
use Illuminate\Database\Seeder;

class MeetingSeeder extends Seeder
{
  public function run()
  {
    $meetings = [
      [
        'slug' => 'sunday-morning-service',
        'type' => 'SundayAndBibleStudies',
        'StartTime' => '10:30:00',
        'EndTime' => '11:45:00',
        'day' => 'Sunday',
        'location' => 'Main Church Building',
        'who' => 'All Ages Welcome',
        'pictures' => true,
        'LeadersEmail' => 'mark@crockenhill.org',
      ],
      [
        'slug' => 'sunday-evening-service',
        'type' => 'SundayAndBibleStudies',
        'StartTime' => '18:30:00',
        'EndTime' => '19:30:00',
        'day' => 'Sunday',
        'location' => 'Main Church Building',
        'who' => 'All Ages Welcome',
        'pictures' => true,
        'LeadersEmail' => 'mark@crockenhill.org',
      ],
      [
        'slug' => 'wednesday-bible-study',
        'type' => 'SundayAndBibleStudies',
        'StartTime' => '19:30:00',
        'EndTime' => '20:30:00',
        'day' => 'Wednesday',
        'location' => 'Church Hall',
        'who' => 'Adults',
        'pictures' => false,
        'LeadersEmail' => 'mark@crockenhill.org',
      ],
      [
        'slug' => 'youth-group',
        'type' => 'ChildrenAndYoungPeople',
        'StartTime' => '19:00:00',
        'EndTime' => '20:30:00',
        'day' => 'Friday',
        'location' => 'Youth Room',
        'who' => 'Ages 11-18',
        'pictures' => true,
        'LeadersPhone' => '1234567890',
        'LeadersEmail' => 'youth@crockenhill.org',
      ],
      [
        'slug' => 'sunday-school',
        'type' => 'ChildrenAndYoungPeople',
        'StartTime' => '10:30:00',
        'EndTime' => '11:30:00',
        'day' => 'Sunday',
        'location' => 'Children\'s Room',
        'who' => 'Ages 3-11',
        'pictures' => true,
        'LeadersEmail' => 'children@crockenhill.org',
      ],
    ];

    foreach ($meetings as $meeting) {
      Meeting::create($meeting);
    }

    Meeting::factory(5)->create();
  }
}
