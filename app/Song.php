<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Song extends Model {
 
    use HasFactory;
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