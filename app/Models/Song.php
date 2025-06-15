<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
  use HasFactory;

  protected $fillable = [
    'praise_number',
    'title',
    'author',
    'lyrics',
    'copyright',
    'alternative_title',
    'current',
    'notes',
    'major_category',
    'minor_category',
  ];

  protected $casts = [
    'current' => 'boolean',
  ];

  public function scriptureReferences()
  {
    return $this->hasMany(ScriptureReference::class);
  }

  public function playDates()
  {
    return $this->hasMany(PlayDate::class);
  }

  public function scopeCurrent($query)
  {
    return $query->where('current', true);
  }

  public function scopeByCategory($query, $category)
  {
    return $query->where('major_category', $category)
      ->orWhere('minor_category', $category);
  }

  public function getLastPlayedAttribute()
  {
    return $this->playDates()->latest('date')->first()?->date;
  }
}
