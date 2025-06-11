<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Song;

class ScriptureReference extends Model {
    use HasFactory;
 
    protected $table = 'scripture_references';

    public function post()
    {
      return $this->belongsTo(Song::class);
    }
 
}