<?php

class Playdate extends Eloquent {

	protected $table = 'play_date';

    public $timestamps = false;

    public function song()
    {
        return $this->belongsTo('Song');
    }
}
