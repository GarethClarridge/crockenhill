<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalendarEventStatus;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property string $google_event_id
 * @property string|null $meeting_slug
 * @property string $title
 * @property string|null $description
 * @property string|null $speaker
 * @property string|null $location
 * @property Carbon $start_datetime
 * @property Carbon $end_datetime
 * @property CalendarEventStatus $status
 * @property bool $is_categorized_automatically
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    protected $fillable = [
        'google_event_id',
        'meeting_slug',
        'title',
        'description',
        'speaker',
        'location',
        'start_datetime',
        'end_datetime',
        'status',
        'is_categorized_automatically',
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
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'status' => CalendarEventStatus::class,
            'is_categorized_automatically' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function googleEventId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function speaker(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value !== null ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function location(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value !== null ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'google_event_id' => ['required', 'string', 'max:255'],
            'meeting_slug' => ['nullable', 'string', 'max:255', 'exists:meetings,slug'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'speaker' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after_or_equal:start_datetime'],
            'status' => ['required', Rule::enum(CalendarEventStatus::class)],
            'is_categorized_automatically' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return BelongsTo<Meeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_slug', 'slug');
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeForCard(Builder $query): Builder
    {
        return $query->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime']);
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_datetime', '>=', now());
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopePast(Builder $query): Builder
    {
        return $query->where('start_datetime', '<', now());
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', CalendarEventStatus::Confirmed);
    }
}
