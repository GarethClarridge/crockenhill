<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $historic_import_operation_id
 * @property int|null $media_processing_log_id
 * @property string $alert_key
 * @property string $kind
 * @property string $severity
 * @property array<string, mixed> $payload
 * @property Carbon $recorded_at
 */
class HistoricImportAlert extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'historic_import_operation_id',
        'media_processing_log_id',
        'alert_key',
        'kind',
        'severity',
        'payload',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historic import alert facts are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Historic import alert facts cannot be deleted through the model.');
        });
    }

    /** @return BelongsTo<HistoricImportOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(HistoricImportOperation::class, 'historic_import_operation_id');
    }

    /** @return BelongsTo<MediaProcessingLog, $this> */
    public function processingLog(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
