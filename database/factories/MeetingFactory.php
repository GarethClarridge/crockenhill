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

        return [
            'slug' => Str::slug($title),
            'type' => $this->faker->randomElement([
                'SundayAndBibleStudies',
                'ChildrenAndYoungPeople',
                'Adults',
                'Occasional',
            ]),
            'start_time' => $start = $this->faker->optional()->time('H:i'),
            'end_time' => $start ? Carbon::parse($start)->addMinutes($this->faker->numberBetween(30, 180))->format('H:i') : null,
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
                'start_time' => $date->format('H:i'),
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
                'start_time' => $date->format('H:i'),
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
                'start_time' => $date->format('H:i'),
            ];
        });
    }
}
