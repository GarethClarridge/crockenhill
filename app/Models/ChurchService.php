<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon $date
 * @property SermonService $service
 * @property string $source
 * @property string|null $original_filename
 * @property bool $needs_review
 * @property array<string, mixed>|null $import_metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChurchServiceItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MediaProcessingLog> $mediaProcessingLogs
 *
 * @method static \Database\Factories\ChurchServiceFactory factory(...$parameters)
 * @method static Builder<ChurchService> newModelQuery()
 * @method static Builder<ChurchService> newQuery()
 * @method static Builder<ChurchService> query()
 *
 * @mixin \Eloquent
 */
class ChurchService extends Model
{
    /** @use HasFactory<\Database\Factories\ChurchServiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'service',
        'source',
        'original_filename',
        'needs_review',
        'import_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'service' => SermonService::class,
            'needs_review' => 'boolean',
            'import_metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<ChurchServiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChurchServiceItem::class);
    }

    /**
     * @return HasMany<MediaProcessingLog, $this>
     */
    public function mediaProcessingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }

    public function touchForSectionReconciliation(): void
    {
        $timestamp = $this->freshTimestamp();

        if (
            $this->updated_at instanceof \Illuminate\Support\Carbon
            && $this->updated_at->format('Y-m-d H:i:s') === $timestamp->format('Y-m-d H:i:s')
        ) {
            $timestamp = $this->updated_at->copy()->addSecond();
        }

        $this->forceFill([
            'updated_at' => $timestamp,
        ])->save();
    }
}
