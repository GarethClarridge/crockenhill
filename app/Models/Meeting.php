<?php

namespace App\Models;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Services\CalendarService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\GoogleCalendar\Event;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;

/**
 * App\Models\Meeting
 *
 * @property int $id
 * @property ?int $page_id
 * @property string $slug
 * @property MeetingType $type
 * @property ?Carbon $StartTime // Cast to datetime:H:i:s -> Carbon
 * @property ?Carbon $EndTime // Cast to datetime:H:i:s -> Carbon
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
 * @property-read string $heading
 * @property-read ?string $description
 * @property-read ?string $body
 * @property-read ?string $markdown
 * @property-read ?string $heading_image_url
 * @property-read Page|null $page
 *
 * @method static \Database\Factories\MeetingFactory factory(...$parameters)
 * @method static Builder|Meeting newModelQuery()
 * @method static Builder|Meeting newQuery()
 * @method static Builder|Meeting query()
 * @method static Builder|Meeting isRecurring()
 * @method static Builder|Meeting upcoming()
 * @method static Builder|Meeting onDate(Carbon $date)
 *
 * @mixin \Eloquent
 */
class Meeting extends Model implements Sitemapable
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
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
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the page that provides content for this meeting.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the heading from the related page, or generate from slug.
     */
    public function getHeadingAttribute(): string
    {
        return $this->page->heading ?? Str::title(str_replace('-', ' ', $this->slug));
    }

    /**
     * Get the description from the related page.
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->page?->description;
    }

    /**
     * Get the body content from the related page.
     */
    public function getBodyAttribute(): ?string
    {
        return $this->page?->body;
    }

    /**
     * Get the markdown content from the related page.
     */
    public function getMarkdownAttribute(): ?string
    {
        return $this->page?->markdown;
    }

    /**
     * Get the heading image URL from the related page.
     */
    public function getHeadingImageUrlAttribute(): ?string
    {
        return $this->page?->heading_image_url;
    }

    /**
     * Check if the meeting has rich content via a related page.
     */
    public function hasContent(): bool
    {
        return $this->page !== null;
    }

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
        if (! $this->is_recurring || ! $this->meeting_date || ! $this->frequency) {
            return null;
        }

        $nextOccurrence = $this->meeting_date->copy();
        $now = now();

        if ($nextOccurrence->gte($now)) {
            return $nextOccurrence;
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

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'meeting_slug', 'slug');
    }

    public function getUpcomingEventsAttribute(): Collection
    {
        return \App\Models\CalendarEvent::where('meeting_slug', $this->slug)
            ->upcoming()
            ->confirmed()
            ->orderBy('start_datetime')
            ->limit(config('calendar.performance.eager_load_limit', 100))
            ->get();
    }

    public function getPastEventsAttribute(): Collection
    {
        return \App\Models\CalendarEvent::where('meeting_slug', $this->slug)
            ->past()
            ->confirmed()
            ->orderBy('start_datetime', 'desc')
            ->limit(config('calendar.performance.eager_load_limit', 100))
            ->get();
    }

    public function getNextEventAttribute(): ?CalendarEvent
    {
        /** @var \App\Models\CalendarEvent|null */
        return $this->upcoming_events->first();
    }

    public function getLastEventAttribute(): ?CalendarEvent
    {
        /** @var \App\Models\CalendarEvent|null */
        return $this->past_events->first();
    }

    public function createEvent(array $eventData): Event
    {
        return app(CalendarService::class)->createEventForMeeting($this->slug, $eventData);
    }

    /**
     * Convert the meeting to a sitemap tag.
     */
    public function toSitemapTag(): Url|string|array
    {
        $url = Url::create("/community/{$this->slug}")
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.6);

        if ($this->updated_at) {
            $url->setLastModificationDate($this->updated_at);
        }

        return $url;
    }
}
