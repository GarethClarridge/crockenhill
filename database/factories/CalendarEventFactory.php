<?php

namespace Database\Factories;

use App\Enums\CalendarEventStatus;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 day', '+30 days');

        return [
            'google_event_id' => $this->faker->uuid(),
            'meeting_slug' => null,
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'speaker' => $this->faker->name(),
            'location' => $this->faker->city(),
            'start_datetime' => $start,
            'end_datetime' => (clone $start)->modify('+'.rand(0, 180).' minutes'),
            'status' => CalendarEventStatus::Confirmed,
            'is_categorized_automatically' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CalendarEvent $event): void {
            if ($event->end_datetime < $event->start_datetime) {
                $event->end_datetime = $event->start_datetime->copy()->addHour();
            }
        });
    }

    /**
     * Indicate that the event is pending categorization.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CalendarEventStatus::Pending,
        ]);
    }

    /**
     * Indicate that the event was categorized automatically.
     */
    public function automaticallyCategorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_categorized_automatically' => true,
        ]);
    }

    /**
     * Event starts in the future.
     */
    public function upcoming(): static
    {
        return $this->state(function (array $attributes) {
            $start = $this->faker->dateTimeBetween('+1 day', '+30 days');
            $end = (clone $start)->modify('+1 hour');

            return [
                'start_datetime' => $start,
                'end_datetime' => $end,
            ];
        });
    }

    /**
     * Event has already occurred.
     */
    public function past(): static
    {
        return $this->state(function (array $attributes) {
            $start = $this->faker->dateTimeBetween('-30 days', '-1 day');
            $end = (clone $start)->modify('+1 hour');

            return [
                'start_datetime' => $start,
                'end_datetime' => $end,
            ];
        });
    }

    /**
     * Associate the event with a specific meeting.
     */
    public function forMeeting(Meeting $meeting): static
    {
        return $this->state(fn (array $attributes) => [
            'meeting_slug' => $meeting->slug,
        ]);
    }
}
