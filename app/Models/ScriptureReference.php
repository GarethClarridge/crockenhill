<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScriptureReference extends Model
{
  use HasFactory;

  protected $fillable = [
    'reference',
    'song_id',
  ];

  public function song()
  {
    return $this->belongsTo(Song::class);
  }

  public function scopeByBook($query, $book)
  {
    return $query->where('reference', 'like', $book . '%');
  }
}
