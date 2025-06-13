<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'slug',
        'type',
        'StartTime',
        'EndTime',
        'day',
        'location',
        'who',
        'LeadersPhone',
        'LeadersEmail',
        'pictures',
    ];
}
