<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
  use HasFactory;

  protected $fillable = [
    'slug',
    'heading',
    'description',
    'area',
    'body',
    'admin',
    'markdown',
    'navigation',
  ];

  protected $casts = [
    'navigation' => 'boolean',
  ];

  public function getRouteKeyName()
  {
    return 'slug';
  }

  public function scopeNavigation($query)
  {
    return $query->where('navigation', true);
  }

  public function scopePublic($query)
  {
    return $query->where('admin', 'no');
  }

  public function scopeAdmin($query)
  {
    return $query->where('admin', 'yes');
  }
}
