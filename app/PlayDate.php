<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Song; // Assuming Song model will also be in App\Models

class PlayDate extends Model {
    use HasFactory;
 
    protected $table = 'play_date';

    public function post()
    {
      return $this->belongsTo(Song::class);
    }
 
}