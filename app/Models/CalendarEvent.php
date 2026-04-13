<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $google_event_id
 * @property string|null $meeting_slug
 * @property string $title
 * @property string|null $description
 * @property string|null $speaker
 * @property string|null $location
 * @property \Illuminate\Support\Carbon $start_datetime
 * @property \Illuminate\Support\Carbon $end_datetime
 * @property \App\Enums\CalendarEventStatus $status
 * @property bool $is_categorized_automatically
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CalendarEvent extends Model
{
    /** @use HasFactory<\Database\Factories\CalendarEventFactory> */
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
            'status' => \App\Enums\CalendarEventStatus::class,
            'is_categorized_automatically' => 'boolean',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'speaker' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.implode(',', \App\Enums\CalendarEventStatus::values())],
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
        return $query->where('status', 'confirmed');
    }
}
