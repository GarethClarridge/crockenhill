<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder; // For scope return types
// use Spatie\Feed\Feedable; // Not used in this file
// use Spatie\Feed\FeedItem; // Not used in this file
use Illuminate\Support\Str;
use Illuminate\Support\Carbon; // For type hinting Carbon instances
use App\Enums\SermonService; // Added Enum import

/**
 * App\Models\Sermon
 *
 * @property int $id
 * @property Carbon $date
 * @property ?SermonService $service
 * @property string $filename
 * @property string $filetype
 * @property string $title
 * @property string $slug
 * @property ?string $reference
 * @property string $preacher
 * @property ?string $series
 * @property ?array $points  // Accessor returns array
 * @property-read ?string $human_date
 * @property-read ?string $audio_url
 * @property-read ?string $series_url
 * @property-read ?string $preacher_url
 * @method static \Database\Factories\SermonFactory factory(...$parameters)
 * @method static Builder|Sermon newModelQuery()
 * @method static Builder|Sermon newQuery()
 * @method static Builder|Sermon query()
 * @method static Builder|Sermon last12Months()
 * @method static Builder|Sermon forService(string $serviceType)
 * @method static Builder|Sermon inSeries(string $seriesTitle)
 * @method static Builder|Sermon byPreacher(string $preacherName)
 * @mixin \Eloquent
 */
class Sermon extends Model
{
  use HasFactory;
  protected $table = 'sermons';

  public $timestamps = false;

  /**
   * The attributes that are mass assignable.
   *
   * @var list<string>
   */
  protected $fillable = [
    'title',
    'filename',
    'filetype', // Added filetype as it's in the migration
    'date',
    'service',
    'slug',
    'series',
    'reference',
    'preacher',
    'points', // Stored as JSON string, handled by accessor/mutator potentially
  ];

  /**
   * The attributes that should be cast.
   *
   * @var array<string, string>
   */
  protected $casts = [
    'date' => 'date',
    'points' => 'array', // Let Eloquent handle the casting to array for `points`
    'service' => SermonService::class,
  ];

  // Accessor for points is no longer strictly needed if 'points' => 'array' cast is used.
  // Eloquent's 'array' cast will handle JSON decode/encode.
  // If custom logic beyond simple JSON (like specific object mapping) was needed, accessor/mutator is good.
  // For now, relying on 'array' cast simplifies.
  // public function getPointsAttribute($value): ?array
  // {
  //   if (is_string($value)) {
  //     $decoded = json_decode($value, true);
  //     return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
  //   }
  //   return is_array($value) ? $value : null; // Ensure it's an array or null
  // }

  // public function setPointsAttribute($value): void
  // {
  //   $this->attributes['points'] = is_array($value) ? json_encode($value) : null;
  // }


  public function getHumanDateAttribute(): ?string
  {
    return $this->date->format('F j, Y');
  }

  public function getAudioUrlAttribute(): ?string
  {
    // Assuming filename already includes 'public/media/sermons/' path part or similar
    // Or if it's just the filename, Storage::url() should be used.
    // The original url($this->filename) might be problematic if filename is not a full public path.
    // For now, keeping original logic, but this is a common point of failure.
    // If 'filename' stores something like 'sermons/audio.mp3' and 'public' disk is the default for url(),
    // then Storage::disk('public')->url($this->filename) would be more robust.
    // Given the `PostSermonRequest` stores to `Storage::disk('public')->putFile('sermons', $file);`
    // the path stored in `filename` will be like `sermons/generated_name.mp3`.
    // So, Storage::url() is appropriate.
    return $this->filename ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->filename) : null;
  }

  public function getSeriesUrlAttribute(): ?string
  {
    return $this->series ? '/christ/sermons/series/' . Str::slug($this->series) : null;
  }

  public function getPreacherUrlAttribute(): ?string
  {
    return $this->preacher ? '/christ/sermons/preachers/' . Str::slug($this->preacher) : null;
  }

  public function scopeLast12Months(Builder $query): Builder
  {
    return $query->where('date', '>=', now()->subMonths(12)->startOfDay());
  }

  public function scopeForService(Builder $query, string $serviceType): Builder
  {
    return $query->where('service', $serviceType);
  }

  public function scopeInSeries(Builder $query, string $seriesTitle): Builder
  {
    return $query->where('series', $seriesTitle);
  }

  public function scopeByPreacher(Builder $query, string $preacherName): Builder
  {
    return $query->where('preacher', $preacherName);
  }
}
