<?php namespace Crockenhill;

class Song extends \Eloquent {
 
    protected $table = 'songs';

    public function play_date()
    {
      return $this->hasMany('PlayDate', 'song_id');
    }

    public function scripture_reference()
    {
      return $this->hasMany('ScriptureReference', 'song_id');
    }
 
}