<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;
use App\Models\Service; // Added import

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

  /**
   * Get the service that this sermon belongs to.
   */
  public function service()
  {
      return $this->belongsTo(Service::class);
  }

  /**
   * Get the full URL to the sermon audio file.
   *
   * @return string|null
   */
  public function getAudioUrlAttribute(): ?string
  {
      if ($this->filename) {
          // Assuming 'filename' is just the file name and files are in 'public/audio/'
          // or a symlinked 'storage/app/public/audio/'
          return url('audio/' . $this->filename);
      }
      return null;
  }
}
