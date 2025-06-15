<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
  use HasFactory;

  protected $fillable = [
    'slug',
    'type',
    'StartTime',
    'EndTime',
    'day',
    'location',
    'who',
    'pictures',
    'LeadersPhone',
    'LeadersEmail',
  ];

  protected $casts = [
    'StartTime' => 'datetime:H:i:s',
    'EndTime' => 'datetime:H:i:s',
    'pictures' => 'boolean',
  ];

  public function getRouteKeyName()
  {
    return 'slug';
  }

  public function scopeByType($query, $type)
  {
    return $query->where('type', $type);
  }

  public function scopeByDay($query, $day)
  {
    return $query->where('day', $day);
  }

  public function getFormattedTimeAttribute()
  {
    if ($this->StartTime && $this->EndTime) {
      return $this->StartTime->format('g:i A') . ' - ' . $this->EndTime->format('g:i A');
    }
    return null;
  }
}
