<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder; // For scope return types
use Illuminate\Support\Carbon; // For type hinting Carbon instances
use App\Enums\MeetingType; // Added Enum import
use App\Enums\MeetingFrequency; // Added Enum import

/**
 * App\Models\Meeting
 *
 * @property int $id
 * @property string $slug
 * @property MeetingType $type
 * @property ?Carbon $StartTime  // Cast to datetime:H:i:s -> Carbon
 * @property ?Carbon $EndTime    // Cast to datetime:H:i:s -> Carbon
 * @property string $day
 * @property ?string $location
 * @property string $who
 * @property bool $pictures
 * @property ?string $LeadersPhone
 * @property ?string $LeadersEmail
 * @property ?Carbon $meeting_date
 * @property bool $is_recurring
 * @property ?MeetingFrequency $frequency
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?string $formatted_date_time
 * @method static \Database\Factories\MeetingFactory factory(...$parameters)
 * @method static Builder|Meeting newModelQuery()
 * @method static Builder|Meeting newQuery()
 * @method static Builder|Meeting query()
 * @method static Builder|Meeting isRecurring()
 * @method static Builder|Meeting upcoming()
 * @method static Builder|Meeting onDate(Carbon $date)
 * @mixin \Eloquent
 */
class Meeting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'pictures' => 'boolean',
        'meeting_date' => 'datetime',
        'is_recurring' => 'boolean',
        'StartTime' => 'datetime:H:i:s',
        'EndTime' => 'datetime:H:i:s',
        'type' => MeetingType::class,
        'frequency' => MeetingFrequency::class,
    ];

    protected $table = 'meetings';

    /**
     * Get the meeting's formatted date and time.
     * Example: January 15, 2023, 10:30 AM
     */
    public function getFormattedDateTimeAttribute(): ?string
    {
        if ($this->meeting_date) {
            $dateTime = $this->meeting_date;
            // If StartTime is a Carbon instance (due to cast) and represents a valid time
            if ($this->StartTime instanceof Carbon) {
                $dateTime = $this->meeting_date->copy()->setTimeFrom($this->StartTime);
            }
            return $dateTime->format('F j, Y, g:i A');
        }
        return null;
    }

    public function scopeIsRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('meeting_date', '>=', now());
    }

    public function scopeOnDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('meeting_date', $date->toDateString());
    }

    /**
     * Calculate the next occurrence of the meeting.
     * Returns null if the meeting is not recurring or if the meeting_date is not set.
     */
    public function getNextOccurrence(): ?Carbon
    {
        if (!$this->is_recurring || !$this->meeting_date || !$this->frequency) {
            return null;
        }

        $nextOccurrence = $this->meeting_date->copy();
        $now = now();

        if ($nextOccurrence->gte($now)) {
            return $nextOccurrence;
        }

        // Ensure $this->frequency is an instance of MeetingFrequency due to casting
        if (!$this->frequency instanceof MeetingFrequency) {
            return null; // Should not happen if casting is correct and data is valid
        }

        switch ($this->frequency) {
            case MeetingFrequency::DAILY:
                $nextOccurrence = $now->copy()->setTimeFrom($this->meeting_date);
                if ($nextOccurrence->isPast()) {
                    $nextOccurrence->addDay();
                }
                break;
            case MeetingFrequency::WEEKLY:
                $originalMeetingTime = $this->meeting_date; // Time component from original meeting
                $nextOccurrence = $this->meeting_date->copy();
                while ($nextOccurrence->isPast()) {
                    $nextOccurrence->addWeek();
                }
                $nextOccurrence->setTimeFrom($originalMeetingTime); // Ensure time is preserved
                break;
            case MeetingFrequency::MONTHLY:
                $originalMeetingTime = $this->meeting_date;
                $currentMonthOccurrence = $now->copy()->day($originalMeetingTime->day)->setTimeFrom($originalMeetingTime);

                if ($currentMonthOccurrence->isFuture()) {
                    $nextOccurrence = $currentMonthOccurrence;
                } else {
                    $nextOccurrence = $now->copy()->addMonthNoOverflow()->day($originalMeetingTime->day)->setTimeFrom($originalMeetingTime);
                }
                // Ensure it respects the original day if possible, otherwise adjusts (e.g. Feb 30 -> Feb 28/29)
                if ($nextOccurrence->day !== $originalMeetingTime->day) {
                    $nextOccurrence->day($originalMeetingTime->day); // Attempt to set day, Carbon handles overflow by month end
                }
                break;
            case MeetingFrequency::ANNUALLY:
                $originalMeetingTime = $this->meeting_date;
                $currentYearOccurrence = $now->copy()
                    ->month($originalMeetingTime->month)
                    ->day($originalMeetingTime->day)
                    ->setTimeFrom($originalMeetingTime);

                if ($currentYearOccurrence->isFuture()) {
                    $nextOccurrence = $currentYearOccurrence;
                } else {
                    $nextOccurrence = $now->copy()->addYearNoOverflow()
                        ->month($originalMeetingTime->month)
                        ->day($originalMeetingTime->day)
                        ->setTimeFrom($originalMeetingTime);
                }
                 // Ensure it respects the original day if possible
                if ($nextOccurrence->month !== $originalMeetingTime->month || $nextOccurrence->day !== $originalMeetingTime->day) {
                    $nextOccurrence->month($originalMeetingTime->month)->day($originalMeetingTime->day);
                }
                break;
            default:
                // This case should ideally not be reached if frequency is always a valid Enum or null
                return null;
        }
        return $nextOccurrence;
    }
}
