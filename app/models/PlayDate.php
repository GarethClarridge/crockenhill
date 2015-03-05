<?php

class PlayDate extends Eloquent {
 
    protected $table = 'play_date';

    public function post()
    {
      return $this->belongsTo('Song');
    }
 
}