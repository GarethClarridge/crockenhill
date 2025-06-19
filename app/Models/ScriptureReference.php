<?php

namespace App\Models;

class ScriptureReference extends \Eloquent
{

  protected $table = 'scripture_references';

  public function post()
  {
    return $this->belongsTo('Song');
  }
}
