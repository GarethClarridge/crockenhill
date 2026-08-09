<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $historic_import_operation_id
 * @property int|null $historic_import_checkpoint_id
 * @property string $transfer_key
 * @property string $source_disk
 * @property string $source_path
 * @property string $destination_disk
 * @property string $destination_path
 * @property int $byte_size
 * @property string $sha256
 * @property string $state
 * @property int $attempts
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $retain_until
 */
class HistoricImportAssetTransfer extends Model
{
    protected $fillable = [
        'historic_import_operation_id',
        'historic_import_checkpoint_id',
        'transfer_key',
        'source_disk',
        'source_path',
        'destination_disk',
        'destination_path',
        'byte_size',
        'sha256',
        'state',
        'attempts',
        'started_at',
        'verified_at',
        'retain_until',
    ];

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
        return [
            'started_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'retain_until' => 'immutable_datetime',
        ];
    }
}
