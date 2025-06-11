<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\PlayDate;
use App\Models\ScriptureReference;

class Song extends Model {
    use HasFactory;
 
    protected $table = 'songs';

    public function play_date()
    {
      return $this->hasMany(PlayDate::class, 'song_id');
    }

    public function scripture_reference()
    {
      return $this->hasMany(ScriptureReference::class, 'song_id');
    }
 
}