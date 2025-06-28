<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'type',
        'StartTime',
        'EndTime',
        'day',
        'location',
        'who',
        'pictures',
        'LeadersPhone',
        'LeadersEmail',
        'meeting_date',
        'is_recurring',
        'frequency',
    ];

    protected $casts = [
        'pictures' => 'boolean',
        'meeting_date' => 'datetime',
        'is_recurring' => 'boolean',
        'StartTime' => 'datetime:H:i:s', // If you want to cast time strings to Carbon objects
        'EndTime' => 'datetime:H:i:s',   // Same as above
    ];

    /**
     * Get the meeting's formatted date and time.
     * Example: January 15, 2023, 10:30 AM
     *
     * @return string|null
     */
    public function getFormattedDateTimeAttribute(): ?string
    {
        if ($this->meeting_date) {
            // If StartTime is available and meeting_date is just a date, combine them.
            // If meeting_date already has time, and StartTime is just to refine, adjust logic.
            // For now, assuming meeting_date is the primary source of date and time.
            $dateTime = $this->meeting_date;
            if ($this->StartTime) { // If StartTime is set, use its time component
                $timeInstance = $this->StartTime; // Already a Carbon instance due to cast
                $dateTime = $this->meeting_date->setTimeFrom($timeInstance);
            }
            return $dateTime->format('F j, Y, g:i A');
        }
        return null;
    }

    public function scopeIsRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('meeting_date', '>=', now());
    }

    public function scopeOnDate($query, \Carbon\Carbon $date)
    {
        return $query->whereDate('meeting_date', $date->toDateString());
    }

    /**
     * Calculate the next occurrence of the meeting.
     * Returns null if the meeting is not recurring or if the meeting_date is not set.
     *
     * @return \Carbon\Carbon|null
     */
    public function getNextOccurrence(): ?\Carbon\Carbon
    {
        if (!$this->is_recurring || !$this->meeting_date || !$this->frequency) {
            return null;
        }

        $nextOccurrence = $this->meeting_date->copy();
        $now = now();

        // If the initial meeting_date is in the future, that's the next occurrence.
        if ($nextOccurrence->gte($now)) {
            return $nextOccurrence;
        }

        // Calculate next occurrence based on frequency
        // This is a simplified example; a robust solution would be more complex,
        // especially for monthly/annually with varying day counts.
        switch ($this->frequency) {
            case 'daily':
                // Set to today, then if past, set to tomorrow
                $nextOccurrence = $now->setTimeFrom($this->meeting_date);
                if ($nextOccurrence->isPast()) {
                    $nextOccurrence->addDay();
                }
                break;
            case 'weekly':
                // Find the next occurrence of the meeting's day of the week
                // starting from the original meeting_date's day of week.
                $nextOccurrence = $this->meeting_date->copy();
                while ($nextOccurrence->isPast()) {
                    $nextOccurrence->addWeek();
                }
                break;
            case 'monthly':
                // Naive monthly: adds a month. More complex logic needed for specific day (e.g., last day, Nth weekday).
                $nextOccurrence = $this->meeting_date->copy();
                while ($nextOccurrence->isPast() || $nextOccurrence->month === $this->meeting_date->month && $nextOccurrence->year === $this->meeting_date->year) {
                     // Ensure we move to the next month if the original date is also in the past
                    if($nextOccurrence->isPast() && $nextOccurrence->month == $now->month && $nextOccurrence->year == $now->year) {
                         $nextOccurrence = $now->copy()->addMonth()->startOfMonth()->addDays($this->meeting_date->day -1);
                         $nextOccurrence->setTimeFrom($this->meeting_date);
                         // If this calculated day is invalid for the month (e.g. Feb 30), it will adjust.
                         // Or handle specific day logic e.g. last Xday of month
                         break;
                    }
                    $nextOccurrence->addMonthNoOverflow(); // Add a month, ensuring it doesn't jump to next if day is too high
                }
                 // If after adding months, it's still in the past (e.g., original date was March 31st, next is April 30th, but current is May 1st)
                // then we need to ensure it's truly the *next* one from now.
                if ($nextOccurrence->isPast()) {
                    $nextOccurrence = $now->copy()->addMonthNoOverflow()->setDate($now->year, $now->month, $this->meeting_date->day)->setTimeFrom($this->meeting_date);
                    if ($nextOccurrence->isPast()){ // if it's still in the past (e.g. trying to set 31st on a 30 day month and it became past)
                        $nextOccurrence->addMonthNoOverflow();
                    }
                }

                break;
            case 'annually':
                $nextOccurrence = $this->meeting_date->copy();
                while ($nextOccurrence->isPast() || ($nextOccurrence->year === $this->meeting_date->year && $nextOccurrence->month === $this->meeting_date->month && $nextOccurrence->day === $this->meeting_date->day)) {
                    if($nextOccurrence->isPast() && $nextOccurrence->year == $now->year) {
                        $nextOccurrence = $now->copy()->addYear()->startOfYear()
                                           ->month($this->meeting_date->month)->day($this->meeting_date->day)
                                           ->setTimeFrom($this->meeting_date);
                        break;
                    }
                    $nextOccurrence->addYearWithNoOverflow();
                }
                 if ($nextOccurrence->isPast()) {
                    $nextOccurrence = $now->copy()->addYearWithNoOverflow()->month($this->meeting_date->month)->day($this->meeting_date->day)->setTimeFrom($this->meeting_date);
                     if ($nextOccurrence->isPast()){
                        $nextOccurrence->addYearWithNoOverflow();
                    }
                }
                break;
            default:
                return null; // Unknown frequency
        }
        return $nextOccurrence->setTimeFrom($this->meeting_date); // Ensure time component is preserved
    }
}
