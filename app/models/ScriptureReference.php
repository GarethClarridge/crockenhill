<?php

class ScriptureReference extends Eloquent {
 
    protected $table = 'scripture_reference';

    public function post()
    {
      return $this->belongsTo('Song');
    }
 
}