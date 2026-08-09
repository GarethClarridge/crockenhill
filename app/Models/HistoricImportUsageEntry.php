<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $historic_import_checkpoint_id
 * @property string $item_key
 * @property string $provider
 * @property string $model
 * @property int $cost_minor_units
 * @property string $currency
 */
class HistoricImportUsageEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'historic_import_operation_id',
        'historic_import_checkpoint_id',
        'request_key',
        'item_key',
        'provider',
        'model',
        'calls',
        'input_tokens',
        'output_tokens',
        'audio_seconds',
        'cost_minor_units',
        'currency',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historic import usage entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Historic import usage entries cannot be deleted through the model.');
        });
    }

    /** @return BelongsTo<HistoricImportOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(HistoricImportOperation::class, 'historic_import_operation_id');
    }

    /** @return BelongsTo<HistoricImportCheckpoint, $this> */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(HistoricImportCheckpoint::class, 'historic_import_checkpoint_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
