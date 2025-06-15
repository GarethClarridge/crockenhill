<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sermon extends Model
{
  use HasFactory;

  protected $fillable = [
    'date',
    'service',
    'filename',
    'filetype',
    'title',
    'slug',
    'reference',
    'preacher',
    'series',
    'points',
  ];

  protected $casts = [
    'date' => 'date',
  ];

  public function getRouteKeyName()
  {
    return 'slug';
  }

  public function scopeByPreacher($query, $preacher)
  {
    return $query->where('preacher', $preacher);
  }

  public function scopeBySeries($query, $series)
  {
    return $query->where('series', $series);
  }

  public function scopeByService($query, $service)
  {
    return $query->where('service', $service);
  }
}
