<?php namespace Crockenhill;

class Meeting extends \Eloquent 
{
    protected $fillable = [
        'type',
        'slug',
        'day',
        'location',
        'who',
        'pictures',
    ];
}
