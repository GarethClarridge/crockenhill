<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Enums\PageArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;

/**
 * App\Models\Meeting
 *
 * @property int $id
 * @property ?int $page_id
 * @property string $slug
 * @property MeetingType $type
 * @property ?Carbon $start_time
 * @property ?Carbon $end_time
 * @property string $day
 * @property ?string $location
 * @property string $who
 * @property bool $pictures
 * @property ?string $leaders_phone
 * @property ?string $leaders_email
 * @property ?Carbon $meeting_date
 * @property bool $is_recurring
 * @property ?MeetingFrequency $frequency
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?string $formatted_date_time
 * @property-read string $heading
 * @property-read Page|null $page
 *
 * @method static \Database\Factories\MeetingFactory factory(...$parameters)
 * @method static Builder|Meeting newModelQuery()
 * @method static Builder|Meeting newQuery()
 * @method static Builder|Meeting query()
 * @method static Builder|Meeting isRecurring()
 * @method static Builder|Meeting upcoming()
 * @method static Builder|Meeting onDate(Carbon $date)
 * @method static Builder|Meeting publiclyAccessible()
 *
 * @mixin \Eloquent
 */
class Meeting extends Model implements HasMedia, Sitemapable
{
    /** @use HasFactory<\Database\Factories\MeetingFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'slug',
        'type',
        'start_time',
        'end_time',
        'day',
        'location',
        'who',
        'pictures',
        'leaders_phone',
        'leaders_email',
        'meeting_date',
        'is_recurring',
        'frequency',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'pictures' => 'boolean',
            'meeting_date' => 'datetime',
            'is_recurring' => 'boolean',
            'start_time' => 'datetime:H:i:s',
            'end_time' => 'datetime:H:i:s',
            'type' => MeetingType::class,
            'frequency' => MeetingFrequency::class,
        ];
    }

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
    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the heading from the related page, or generate from slug.
     *
     * @return Attribute<string, never>
     */
    protected function heading(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->page->heading ?? Str::title(str_replace('-', ' ', $this->slug))
        )->shouldCache();
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
     *
     * @return Attribute<?string, never>
     */
    protected function formattedDateTime(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if ($this->meeting_date) {
                    $dateTime = $this->meeting_date;
                    // If start_time is a Carbon instance (due to cast) and represents a valid time
                    if ($this->start_time instanceof Carbon) {
                        $dateTime = $this->meeting_date->copy()->setTimeFrom($this->start_time);
                    }

                    return $dateTime->format('F j, Y, g:i A');
                }

                return null;
            }
        )->shouldCache();
    }

    /**
     * Scope to meetings whose linked page, if any, is visible to guests.
     *
     * Meetings without a linked page are considered publicly accessible.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopePubliclyAccessible(Builder $query): Builder
    {
        return $query->whereDoesntHave('page', function (Builder $query): void {
            $query
                ->where('admin', 'yes')
                ->orWhere('area', PageArea::MEMBERS->value);
        });
    }

    /**
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeIsRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }

    /**
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('meeting_date', '>=', now());
    }

    /**
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
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
        $meetingDate = $this->meeting_date;

        if (! $this->is_recurring || ! $meetingDate instanceof Carbon || ! $this->frequency) {
            return null;
        }

        $now = now();

        if ($meetingDate->gte($now)) {
            return $meetingDate->copy();
        }

        return match ($this->frequency) {
            MeetingFrequency::DAILY => $this->calculateNextDailyOccurrence($now, $meetingDate),
            MeetingFrequency::WEEKLY => $this->calculateNextWeeklyOccurrence($now, $meetingDate),
            MeetingFrequency::MONTHLY => $this->calculateNextMonthlyOccurrence($now, $meetingDate),
            MeetingFrequency::ANNUALLY => $this->calculateNextAnnualOccurrence($now, $meetingDate),
        };
    }

    private function calculateNextDailyOccurrence(Carbon $now, Carbon $meetingDate): Carbon
    {
        $nextOccurrence = $now->copy()->setTimeFrom($meetingDate);

        if ($nextOccurrence->isPast()) {
            $nextOccurrence->addDay();
        }

        return $nextOccurrence;
    }

    private function calculateNextWeeklyOccurrence(Carbon $now, Carbon $meetingDate): Carbon
    {
        $nextOccurrence = $meetingDate->copy();

        while ($nextOccurrence->isPast()) {
            $nextOccurrence->addWeek();
        }

        return $nextOccurrence->setTimeFrom($meetingDate);
    }

    private function calculateNextMonthlyOccurrence(Carbon $now, Carbon $meetingDate): Carbon
    {
        $originalDay = $meetingDate->day;
        $currentMonthOccurrence = $now->copy()->day($originalDay)->setTimeFrom($meetingDate);

        if ($currentMonthOccurrence->isFuture()) {
            $nextOccurrence = $currentMonthOccurrence;
        } else {
            $nextOccurrence = $now->copy()->addMonthNoOverflow()->day($originalDay)->setTimeFrom($meetingDate);
        }

        // Ensure it respects the original day if possible, otherwise adjusts (e.g. Feb 30 -> Feb 28/29)
        if ($nextOccurrence->day !== $originalDay) {
            $nextOccurrence->day($originalDay);
        }

        return $nextOccurrence;
    }

    private function calculateNextAnnualOccurrence(Carbon $now, Carbon $meetingDate): Carbon
    {
        $originalMonth = $meetingDate->month;
        $originalDay = $meetingDate->day;

        $currentYearOccurrence = $now->copy()
            ->month($originalMonth)
            ->day($originalDay)
            ->setTimeFrom($meetingDate);

        if ($currentYearOccurrence->isFuture()) {
            $nextOccurrence = $currentYearOccurrence;
        } else {
            $nextOccurrence = $now->copy()->addYearNoOverflow()
                ->month($originalMonth)
                ->day($originalDay)
                ->setTimeFrom($meetingDate);
        }

        // Ensure it respects the original day if possible
        if ($nextOccurrence->month !== $originalMonth || $nextOccurrence->day !== $originalDay) {
            $nextOccurrence->month($originalMonth)->day($originalDay);
        }

        return $nextOccurrence;
    }

    /**
     * Get a list of meetings for admin dropdowns.
     *
     * Performance Optimization: Caches the meeting list for 24 hours using flexible cache
     * to reduce redundant DB queries and eager loading in the admin interface.
     *
     * @return \Illuminate\Support\Collection<string, string>
     */
    public static function getForAdminList(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\Cache::flexible('admin_meeting_list', [86400, 172800], function (): \Illuminate\Support\Collection {
            return self::query()
                ->select(['id', 'slug', 'page_id'])
                ->with('page:id,heading')
                ->get()
                ->mapWithKeys(fn (self $m) => [$m->slug => $m->page->heading ?? $m->slug]);
        });
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'meeting_slug', 'slug');
    }

    /**
     * @return Attribute<Collection<int, CalendarEvent>, never>
     */
    protected function upcomingEvents(): Attribute
    {
        return Attribute::make(
            get: fn (): Collection => $this->calendarEvents()
                ->upcoming()
                ->confirmed()
                ->orderBy('start_datetime')
                ->limit(config('calendar.performance.eager_load_limit', 100))
                ->get()
        );
    }

    /**
     * @return Attribute<Collection<int, CalendarEvent>, never>
     */
    protected function pastEvents(): Attribute
    {
        return Attribute::make(
            get: fn (): Collection => $this->calendarEvents()
                ->past()
                ->confirmed()
                ->orderBy('start_datetime', 'desc')
                ->limit(config('calendar.performance.eager_load_limit', 100))
                ->get()
        );
    }

    /**
     * @return Attribute<?CalendarEvent, never>
     */
    protected function nextEvent(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CalendarEvent => $this->upcoming_events->first()
        );
    }

    /**
     * @return Attribute<?CalendarEvent, never>
     */
    protected function lastEvent(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CalendarEvent => $this->past_events->first()
        );
    }

    /**
     * Convert the meeting to a sitemap tag.
     */
    /**
     * @return Url|string|array<string, mixed>
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

    /**
     * Register media collections for meeting photos.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    /**
     * Register media conversions for meeting photos.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            ->width(400)
            ->height(300)
            ->sharpen(10)
            ->format('webp')
            ->nonQueued();

        $this->addMediaConversion('gallery')
            ->width(800)
            ->height(600)
            ->sharpen(10)
            ->format('webp')
            ->nonQueued();
    }

    /**
     * Get all photos for this meeting from the Media Library.
     *
     * @return Attribute<\Illuminate\Support\Collection<int, array{url: string, thumbnail: string, name: string}>, never>
     */
    protected function photos(): Attribute
    {
        return Attribute::make(
            get: fn (): \Illuminate\Support\Collection => $this->getMedia('photos')->map(fn (Media $media) => [
                'url' => $media->getUrl('gallery'),
                'thumbnail' => $media->getUrl('thumbnail'),
                'name' => $media->name,
            ])
        )->shouldCache();
    }

    /**
     * Check if meeting has any photos in the Media Library.
     */
    public function hasPhotos(): bool
    {
        return $this->getMedia('photos')->isNotEmpty();
    }
}
