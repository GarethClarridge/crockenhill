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
        $startTime = $this->faker->optional()->dateTimeBetween('today 08:00:00', 'today 18:00:00');
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

            'day' => $this->faker->dayOfWeek(),
        ];
    }

    public function onDate(Carbon $date): Factory
    {
        return $this->state(function (array $attributes) use ($date) {
            $start = $date->copy();
            $end = $start->copy()->addHour();

            // If addHour wrapped around to the next day (unlikely but safe to check),
            // or if we just want to ensure end_time is numerically greater for MySQL TIME
            if ($end->format('H:i:s') < $start->format('H:i:s')) {
                $end = $start->copy()->setTime(23, 59, 59);
            }

            return [
                'day' => $date->format('l'),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
            ];
        });
    }

    public function upcoming(): Factory
    {
        return $this->state(function (array $attributes) {
            $date = Carbon::instance($this->faker->dateTimeBetween('+1 day', '+1 year'));
            $start = $date->copy();
            $end = $start->copy()->addHour();

            if ($end->format('H:i:s') < $start->format('H:i:s')) {
                $end = $start->copy()->setTime(23, 59, 59);
            }

            return [
                'day' => $date->format('l'),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
            ];
        });
    }

    public function past(): Factory
    {
        return $this->state(function (array $attributes) {
            $date = Carbon::instance($this->faker->dateTimeBetween('-1 year', '-1 day'));
            $start = $date->copy();
            $end = $start->copy()->addHour();

            if ($end->format('H:i:s') < $start->format('H:i:s')) {
                $end = $start->copy()->setTime(23, 59, 59);
            }

            return [
                'day' => $date->format('l'),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
            ];
        });
    }
}
