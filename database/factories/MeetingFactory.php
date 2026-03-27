<?php

namespace Database\Factories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MeetingFactory extends Factory
{
    public function definition()
    {
        $title = $this->faker->words(3, true);
        $isRecurring = $this->faker->boolean(30);
        $meetingDate = $this->faker->dateTimeBetween('+0 days', '+1 year');

        $startTime = $this->faker->optional()->dateTimeBetween('08:00:00', '18:00:00');
        $endTime = $startTime ? (clone $startTime)->modify('+'.rand(30, 120).' minutes') : null;

        return [
            'slug' => Str::slug($title),
            'type' => $this->faker->randomElement([
                'SundayAndBibleStudies',
                'ChildrenAndYoungPeople',
                'Adults',
                'Occasional',
            ]),
            'start_time' => $startTime?->format('H:i:s'),
            'end_time' => $endTime?->format('H:i:s'),
            'location' => $this->faker->randomElement([
                'Main Hall',
                'Church Building',
                'Community Center',
                'Youth Room',
                'Prayer Room',
            ]),
            'who' => $this->faker->randomElement([
                'All Ages',
                'Adults Only',
                'Children',
                'Youth',
                'Seniors',
            ]),
            'pictures' => $this->faker->boolean(60),
            'leaders_phone' => $this->faker->optional()->numerify('##########'),
            'leaders_email' => $this->faker->optional()->safeEmail(),

            'meeting_date' => $meetingDate,
            'day' => Carbon::parse($meetingDate)->format('l'),
            'is_recurring' => $isRecurring,
            'frequency' => $isRecurring ? $this->faker->randomElement(['daily', 'weekly', 'monthly', 'annually']) : null,
        ];
    }

    public function onDate(Carbon $date): Factory
    {
        return $this->state(function (array $attributes) use ($date) {
            return [
                'meeting_date' => $date,
                'day' => $date->format('l'),
                'start_time' => $date->format('H:i:s'),
                'end_time' => $date->copy()->addHour()->format('H:i:s'),
            ];
        });
    }

    public function recurring($frequency = 'weekly'): Factory
    {
        return $this->state(function (array $attributes) use ($frequency) {
            return [
                'is_recurring' => true,
                'frequency' => $frequency,
            ];
        });
    }

    public function notRecurring(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'is_recurring' => false,
                'frequency' => null,
            ];
        });
    }

    public function upcoming(): Factory
    {
        return $this->state(function (array $attributes) {
            $date = Carbon::instance($this->faker->dateTimeBetween('+1 day', '+1 year'));

            return [
                'meeting_date' => $date,
                'day' => $date->format('l'),
                'start_time' => $date->format('H:i:s'),
                'end_time' => $date->copy()->addHour()->format('H:i:s'),
            ];
        });
    }

    public function past(): Factory
    {
        return $this->state(function (array $attributes) {
            $date = Carbon::instance($this->faker->dateTimeBetween('-1 year', '-1 day'));

            return [
                'meeting_date' => $date,
                'day' => $date->format('l'),
                'start_time' => $date->format('H:i:s'),
                'end_time' => $date->copy()->addHour()->format('H:i:s'),
            ];
        });
    }
}
