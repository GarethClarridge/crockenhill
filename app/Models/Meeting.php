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
}
