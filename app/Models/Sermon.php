<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;
use Illuminate\Support\Str; // Added this line

class Sermon extends Model
{
  use HasFactory;
  protected $table = 'sermons';

  public $timestamps = false;

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

  public function getHumanDateAttribute(): ?string
  {
    return $this->date ? $this->date->format('F j, Y') : null;
  }

  public function getAudioUrlAttribute(): ?string
  {
    return $this->filename ? url($this->filename) : null;
  }

  public function getSeriesUrlAttribute(): ?string
  {
    return $this->series ? '/christ/sermons/series/' . Str::slug($this->series) : null;
  }

  public function getPreacherUrlAttribute(): ?string
  {
    return $this->preacher ? '/christ/sermons/preachers/' . Str::slug($this->preacher) : null;
  }

  public function scopeLast12Months($query)
  {
    return $query->where('date', '>=', now()->subMonths(12)->startOfDay());
  }

  public function scopeForService($query, string $serviceType)
  {
    // This will filter by the enum 'morning' or 'evening'
    // The test also calls this with an ID, which is incorrect for current schema.
    // The test might need adjustment if it was expecting to pass a Service model or ID.
    return $query->where('service', $serviceType);
  }

  public function scopeInSeries($query, string $seriesTitle)
  {
    return $query->where('series', $seriesTitle);
  }

  public function scopeByPreacher($query, string $preacherName)
  {
    return $query->where('preacher', $preacherName);
  }
}
