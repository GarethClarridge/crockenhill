<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Enums\PageArea;
use App\Rules\TrimmedText;
use App\Sitemap\MeetingSitemapPresenter;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
 * @property-read string $heading
 * @property-read Page|null $page
 *
 * @method static MeetingFactory factory(...$parameters)
 * @method static Builder|Meeting newModelQuery()
 * @method static Builder|Meeting newQuery()
 * @method static Builder|Meeting query()
 * @method static Builder|Meeting publiclyAccessible()
 *
 * @mixin \Eloquent
 */
class Meeting extends Model implements HasMedia, Sitemapable
{
    /** @use HasFactory<MeetingFactory> */
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
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'page_id' => 'integer',
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
     * @return Attribute<?string, ?string>
     */
    protected function day(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function who(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function location(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function leadersPhone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function leadersEmail(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? strtolower(trim($value)) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $meeting = null): array
    {
        $slugRule = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $uniqueSlug = Rule::unique('meetings', 'slug');
        if ($meeting) {
            $uniqueSlug->ignore($meeting->id);
        }
        $slugRule[] = $uniqueSlug;

        $pageIdRule = ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:pages,id'];
        $uniquePageId = Rule::unique('meetings', 'page_id');
        if ($meeting) {
            $uniquePageId->ignore($meeting->id);
        }
        $pageIdRule[] = $uniquePageId;

        return [
            'slug' => $slugRule,
            'type' => ['required', Rule::enum(MeetingType::class)],
            'start_time' => ['nullable', 'date_format:H:i:s,H:i'],
            'end_time' => ['nullable', 'date_format:H:i:s,H:i', 'after_or_equal:start_time'],
            'day' => ['nullable', 'string', 'max:75', new TrimmedText],
            'location' => ['nullable', 'string', 'max:255', new TrimmedText],
            'who' => ['required', 'string', 'max:255', new TrimmedText],
            'pictures' => ['boolean'],
            'leaders_phone' => ['nullable', 'string', 'max:255', new TrimmedText],
            'leaders_email' => ['nullable', 'email', 'max:255', new TrimmedText],
            'meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'is_recurring' => ['boolean'],
            'frequency' => ['nullable', 'required_if:is_recurring,true', Rule::enum(MeetingFrequency::class)],
            'page_id' => $pageIdRule,
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
     *
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
                ->orWhere('area', PageArea::Members->value);
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
     * Convert the meeting to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(): Url|string|array
    {
        return app(MeetingSitemapPresenter::class)->toSitemapTag($this);
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
}
