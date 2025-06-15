<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayDate extends Model
{
  use HasFactory;

  protected $table = 'play_date';

  protected $fillable = [
    'song_id',
    'date',
    'time',
  ];

  protected $casts = [
    'date' => 'date',
  ];

  public function song()
  {
    return $this->belongsTo(Song::class);
  }

  public function scopeByDate($query, $date)
  {
    return $query->where('date', $date);
  }

  public function scopeByTime($query, $time)
  {
    return $query->where('time', $time);
  }

  public function scopeMorning($query)
  {
    return $query->where('time', 'a');
  }

  public function scopeEvening($query)
  {
    return $query->where('time', 'p');
  }

  public function getServiceTypeAttribute()
  {
    return $this->time === 'a' ? 'Morning' : 'Evening';
  }
}
