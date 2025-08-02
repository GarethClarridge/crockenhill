<?php

namespace App\Models;

use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory; // For scope return types
// use Spatie\Feed\Feedable; // Not used in this file
// use Spatie\Feed\FeedItem; // Not used in this file
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon; // For type hinting Carbon instances
use Illuminate\Support\Str; // Added Enum import

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
 * @property ?array $points // Accessor returns array
 * @property-read ?string $human_date
 * @property-read ?string $audio_url
 * @property-read ?string $series_url
 * @property-read ?string $preacher_url
 *
 * @method static \Database\Factories\SermonFactory factory(...$parameters)
 * @method static Builder|Sermon newModelQuery()
 * @method static Builder|Sermon newQuery()
 * @method static Builder|Sermon query()
 * @method static Builder|Sermon last12Months()
 * @method static Builder|Sermon forService(string $serviceType)
 * @method static Builder|Sermon inSeries(string $seriesTitle)
 * @method static Builder|Sermon byPreacher(string $preacherName)
 * @method static Builder|Sermon automated()
 * @method static Builder|Sermon manual()
 * @method static Builder|Sermon processingCompleted()
 * @method static Builder|Sermon processingFailed()
 * @method static Builder|Sermon processingInProgress()
 *
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
    'summary', // AI-generated sermon summary
    'transcript_path', // Added for automated sermon processing
    'livestream_processing_id', // Link to livestream processing
    'video_file_path', // Path to sermon video file
    'source_type', // Source type: manual, livestream, upload
    'segment_start_time', // Start time of sermon segment in livestream
    'segment_end_time', // End time of sermon segment in livestream
    'livestream_metadata', // Additional livestream metadata
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
    'livestream_metadata' => 'array',
    'segment_start_time' => 'float',
    'segment_end_time' => 'float',
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

  /**
   * Scope to get only automated sermons
   */
  public function scopeAutomated(Builder $query): Builder
  {
    return $query->where(function ($q) {
      $q->whereNotNull('transcript_path')
        ->orWhereHas('processingLogs');
    });
  }

  /**
   * Scope to get only manually created sermons
   */
  public function scopeManual(Builder $query): Builder
  {
    return $query->where(function ($q) {
      $q->whereNull('transcript_path')
        ->whereDoesntHave('processingLogs');
    });
  }

  /**
   * Scope to get sermons with completed processing
   */
  public function scopeProcessingCompleted(Builder $query): Builder
  {
    return $query->whereHas('processingLogs', function ($q) {
      $q->where('status', \App\Enums\ProcessingStatus::COMPLETED);
    });
  }

  /**
   * Scope to get sermons with failed processing
   */
  public function scopeProcessingFailed(Builder $query): Builder
  {
    return $query->whereHas('processingLogs', function ($q) {
      $q->where('status', \App\Enums\ProcessingStatus::FAILED);
    });
  }

  /**
   * Scope to get sermons currently being processed
   */
  public function scopeProcessingInProgress(Builder $query): Builder
  {
    return $query->whereHas('processingLogs', function ($q) {
      $q->where('status', \App\Enums\ProcessingStatus::PROCESSING);
    });
  }

  /**
   * Get the processing logs for this sermon.
   */
  public function processingLogs(): HasMany
  {
    return $this->hasMany(SermonProcessingLog::class);
  }

  /**
   * Get the livestream processing log for this sermon.
   */
  public function livestreamProcessing(): \Illuminate\Database\Eloquent\Relations\BelongsTo
  {
    return $this->belongsTo(LivestreamProcessingLog::class, 'livestream_processing_id');
  }

  /**
   * Get the transcript content for this sermon
   *
   * @return string|null The transcript content or null if not available
   */
  public function getTranscriptAttribute(): ?string
  {
    if (!$this->transcript_path) {
      return null;
    }

    try {
      return \Illuminate\Support\Facades\Storage::get($this->transcript_path);
    } catch (\Exception $e) {
      \Illuminate\Support\Facades\Log::warning('Failed to read transcript file', [
        'sermon_id' => $this->id,
        'transcript_path' => $this->transcript_path,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
   * Check if this sermon has a transcript available
   *
   * @return bool True if transcript exists and is readable
   */
  public function hasTranscript(): bool
  {
    if (!$this->transcript_path) {
      return false;
    }

    return \Illuminate\Support\Facades\Storage::exists($this->transcript_path);
  }

  /**
   * Get the full path to the transcript file
   *
   * @return string|null The full storage path or null if not set
   */
  public function getTranscriptPath(): ?string
  {
    return $this->transcript_path;
  }

  /**
   * Set the transcript path for this sermon
   *
   * @param string|null $path The storage path to the transcript file
   * @return void
   */
  public function setTranscriptPath(?string $path): void
  {
    $this->transcript_path = $path;
  }

  /**
   * Check if this sermon was created through automated processing
   *
   * @return bool True if sermon was automatically processed
   */
  public function isAutomated(): bool
  {
    return !empty($this->transcript_path) || $this->processingLogs()->exists();
  }

  /**
   * Check if this sermon was created manually
   *
   * @return bool True if sermon was manually created
   */
  public function isManual(): bool
  {
    return !$this->isAutomated();
  }

  /**
   * Get the current processing status for this sermon
   *
   * @return ProcessingStatus|null The current processing status or null if not automated
   */
  public function getProcessingStatus(): ?\App\Enums\ProcessingStatus
  {
    $latestLog = $this->processingLogs()->latest()->first();
    return $latestLog?->status;
  }

  /**
   * Check if processing is complete for this sermon
   *
   * @return bool True if processing is completed
   */
  public function isProcessingComplete(): bool
  {
    $status = $this->getProcessingStatus();
    return $status?->isComplete() ?? false;
  }

  /**
   * Check if processing has failed for this sermon
   *
   * @return bool True if processing has failed
   */
  public function isProcessingFailed(): bool
  {
    $status = $this->getProcessingStatus();
    return $status?->isFailed() ?? false;
  }

  /**
   * Check if processing is currently in progress for this sermon
   *
   * @return bool True if processing is in progress
   */
  public function isProcessingInProgress(): bool
  {
    $status = $this->getProcessingStatus();
    return $status?->isInProgress() ?? false;
  }

  /**
   * Get the latest processing log for this sermon
   *
   * @return SermonProcessingLog|null The latest processing log or null
   */
  public function getLatestProcessingLog(): ?SermonProcessingLog
  {
    return $this->processingLogs()->latest()->first();
  }

  /**
   * Check if this sermon came from a livestream
   *
   * @return bool True if sermon was extracted from livestream
   */
  public function isFromLivestream(): bool
  {
    return $this->source_type === 'livestream';
  }

  /**
   * Check if this sermon has an associated video file
   *
   * @return bool True if video file exists
   */
  public function hasVideo(): bool
  {
    return !empty($this->video_file_path);
  }

  /**
   * Get the video URL for this sermon
   *
   * @return string|null The video URL or null if no video
   */
  public function getVideoUrlAttribute(): ?string
  {
    if (!$this->video_file_path) {
      return null;
    }

    return \Illuminate\Support\Facades\Storage::disk(config('livestream-processing.sermon_disk'))->url($this->video_file_path);
  }

  /**
   * Get the segment duration for livestream sermons
   *
   * @return float|null Duration in seconds or null if not from livestream
   */
  public function getSegmentDuration(): ?float
  {
    if (!$this->isFromLivestream() || !$this->segment_start_time || !$this->segment_end_time) {
      return null;
    }

    return $this->segment_end_time - $this->segment_start_time;
  }

  /**
   * Get formatted segment duration
   *
   * @return string|null Formatted duration or null
   */
  public function getSegmentDurationFormatted(): ?string
  {
    $duration = $this->getSegmentDuration();
    
    if ($duration === null) {
      return null;
    }

    $minutes = floor($duration / 60);
    $seconds = $duration % 60;

    return sprintf('%dm %ds', $minutes, $seconds);
  }

  /**
   * Get livestream metadata with defaults
   *
   * @return array The livestream metadata array
   */
  public function getLivestreamInfo(): array
  {
    if (!$this->isFromLivestream()) {
      return [];
    }

    return [
      'processing_id' => $this->livestream_processing_id,
      'original_filename' => $this->livestreamProcessing?->original_filename,
      'segment_start_time' => $this->segment_start_time,
      'segment_end_time' => $this->segment_end_time,
      'segment_duration' => $this->getSegmentDuration(),
      'segment_duration_formatted' => $this->getSegmentDurationFormatted(),
      'has_video' => $this->hasVideo(),
      'video_url' => $this->getVideoUrlAttribute(),
      'metadata' => $this->livestream_metadata ?? [],
    ];
  }

  /**
   * Scope to get only livestream sermons
   */
  public function scopeFromLivestream(Builder $query): Builder
  {
    return $query->where('source_type', 'livestream');
  }

  /**
   * Scope to get sermons with video files
   */
  public function scopeWithVideo(Builder $query): Builder
  {
    return $query->whereNotNull('video_file_path');
  }

  /**
   * Scope to get sermons by source type
   */
  public function scopeBySourceType(Builder $query, string $sourceType): Builder
  {
    return $query->where('source_type', $sourceType);
  }
}
