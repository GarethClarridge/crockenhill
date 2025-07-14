<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
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

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'is_categorized_automatically' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_slug', 'slug');
    }

    public function scopeUpcoming($query): Builder
    {
        return $query->where('start_datetime', '>=', now());
    }

    public function scopePast($query): Builder
    {
        return $query->where('start_datetime', '<', now());
    }

    public function scopeConfirmed($query): Builder
    {
        return $query->where('status', 'confirmed');
    }
}
