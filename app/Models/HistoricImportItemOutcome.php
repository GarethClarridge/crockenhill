<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportDisposition;
use App\Enums\HistoricImportItemExpectation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $item_key
 * @property HistoricImportItemExpectation $expectation
 * @property HistoricImportDisposition $disposition
 */
class HistoricImportItemOutcome extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'historic_import_operation_id',
        'historic_import_checkpoint_id',
        'historic_import_source_snapshot_id',
        'source_kind',
        'item_key',
        'expectation',
        'disposition',
        'approved_source_sha256',
        'observed_source_sha256',
        'output_hashes',
        'reason_code',
        'settled_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historic import item outcomes are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Historic import item outcomes cannot be deleted through the model.');
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

    /** @return BelongsTo<HistoricImportSourceSnapshot, $this> */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(HistoricImportSourceSnapshot::class, 'historic_import_source_snapshot_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expectation' => HistoricImportItemExpectation::class,
            'disposition' => HistoricImportDisposition::class,
            'output_hashes' => 'array',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
