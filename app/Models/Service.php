<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
  use HasFactory;

  protected $fillable = [
    'date',
    'type',
    'video',
    'audio',
  ];

  protected $casts = [
    'date' => 'date',
  ];

  public function scopeByType($query, $type)
  {
    return $query->where('type', $type);
  }

  public function scopeByDate($query, $date)
  {
    return $query->where('date', $date);
  }

  public function scopeMorning($query)
  {
    return $query->where('type', 'morning');
  }

  public function scopeEvening($query)
  {
    return $query->where('type', 'evening');
  }

  public function getVideoUrlAttribute()
  {
    return asset('storage/' . $this->video);
  }

  public function getAudioUrlAttribute()
  {
    return asset('storage/' . $this->audio);
  }

  public function getFormattedDateAttribute()
  {
    return $this->date->format('F j, Y');
  }

  public function getServiceTitleAttribute()
  {
    return ucfirst($this->type) . ' Service - ' . $this->formatted_date;
  }
}
