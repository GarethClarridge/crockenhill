<?php

namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    protected $table = 'meetings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',        // New field to be added by migration
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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'pictures' => 'boolean', // Based on tinyint(1)
        // StartTime and EndTime are 'time' type, Laravel can handle these as strings
        // or Carbon instances if dates are involved, but for time only, strings are common.
        // No explicit cast needed unless specific Carbon objects are required for time.
    ];

    // Timestamps (created_at, updated_at) are handled by default.

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }
}
