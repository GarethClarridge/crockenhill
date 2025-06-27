<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;

class Sermon extends Model
{
  use HasFactory;
  protected $table = 'sermons';

  public $timestamps = false;

  /**
   * Get the service that this sermon belongs to.
   */
  public function service()
  {
    return $this->belongsTo(\App\Models\Service::class);
  }

  /**
   * The attributes that are mass assignable.
   *
   * @var array<int, string>
   */
  protected $fillable = [
    'title',
    'filename',
    'date',
    'service',
    'slug',
    'series',
    'reference',
    'preacher',
    'points',
  ];

  /**
   * The attributes that should be cast.
   *
   * @var array<string, string>
   */
  protected $casts = [
    'date' => 'date',
    'points' => 'array',
  ];
}
