<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Database\Seeder;

class CalendarEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Regular recurring meetings — seed upcoming instances spanning several weeks
        $recurringMeetingSlugs = [
            'sunday-mornings',
            'sunday-evenings',
            'prayer-meeting',
            'bible-study',
            'coffee-cup',
            'adventurers',
        ];

        foreach ($recurringMeetingSlugs as $slug) {
            $meeting = Meeting::where('slug', $slug)->first();

            if (! $meeting) {
                continue;
            }

            CalendarEvent::factory()
                ->count(4)
                ->upcoming()
                ->forMeeting($meeting)
                ->create(['title' => $meeting->slug]);

            CalendarEvent::factory()
                ->count(2)
                ->past()
                ->forMeeting($meeting)
                ->create(['title' => $meeting->slug]);
        }

        // Occasional events — a couple upcoming, a couple past
        $occasionalMeetingSlugs = [
            'messy-church',
            'family-talk',
        ];

        foreach ($occasionalMeetingSlugs as $slug) {
            $meeting = Meeting::where('slug', $slug)->first();

            if (! $meeting) {
                continue;
            }

            CalendarEvent::factory()
                ->count(2)
                ->upcoming()
                ->forMeeting($meeting)
                ->create(['title' => $meeting->slug]);

            CalendarEvent::factory()
                ->count(1)
                ->past()
                ->forMeeting($meeting)
                ->create(['title' => $meeting->slug]);
        }
    }
}
