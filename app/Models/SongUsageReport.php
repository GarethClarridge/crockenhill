<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use Database\Factories\SongUsageReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A source-backed statement that a song was used on a date, without claiming a complete service
 * order. A null reported_service means the source did not distinguish morning from evening.
 *
 * @property int $id
 * @property int|null $song_id
 * @property Carbon $used_on
 * @property SermonService|null $reported_service
 * @property int|null $resolved_church_service_item_id
 * @property string $reported_title
 * @property string|null $reported_number
 * @property string|null $catalog_title
 * @property string|null $match_method
 * @property string $source_workbook
 * @property string $source_sheet
 * @property int $source_row
 * @property string $source_fingerprint
 * @property array<string, mixed>|null $metadata
 * @property SermonPublicationState $publication_state
 * @property int|null $historic_import_operation_id
 * @property-read Song|null $song
 * @property-read ChurchServiceItem|null $resolvedChurchServiceItem
 * @property-read HistoricImportOperation|null $historicImportOperation
 */
class SongUsageReport extends Model
{
    /** @use HasFactory<SongUsageReportFactory> */
    use HasFactory;

    protected $fillable = [
        'song_id',
        'used_on',
        'reported_service',
        'resolved_church_service_item_id',
        'reported_title',
        'reported_number',
        'catalog_title',
        'match_method',
        'source_workbook',
        'source_sheet',
        'source_row',
        'source_fingerprint',
        'metadata',
        'publication_state',
        'historic_import_operation_id',
    ];

    protected function casts(): array
    {
        return [
            'song_id' => 'integer',
            'used_on' => 'date',
            'reported_service' => SermonService::class,
            'resolved_church_service_item_id' => 'integer',
            'source_row' => 'integer',
            'metadata' => 'array',
            'publication_state' => SermonPublicationState::class,
            'historic_import_operation_id' => 'integer',
        ];
    }

    /**
     * Imported hymn evidence is held until `historic-import:release-batch` publishes it, so every
     * public read consumes the same release decision the imported sermons and song videos do.
     *
     * @param  Builder<SongUsageReport>  $query
     * @return Builder<SongUsageReport>
     */
    public function scopePubliclyReleased(Builder $query): Builder
    {
        return $query->where('publication_state', SermonPublicationState::Published);
    }

    /** @return BelongsTo<HistoricImportOperation, $this> */
    public function historicImportOperation(): BelongsTo
    {
        return $this->belongsTo(HistoricImportOperation::class);
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return BelongsTo<ChurchServiceItem, $this> */
    public function resolvedChurchServiceItem(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceItem::class, 'resolved_church_service_item_id');
    }
}
