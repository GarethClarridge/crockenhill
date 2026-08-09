<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HistoricImportSourceSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'historic_import_operation_id',
        'historic_import_checkpoint_id',
        'historic_import_artifact_id',
        'source_kind',
        'item_key',
        'file_key',
        'relative_path',
        'approved_sha256',
        'observed_sha256',
        'byte_size',
        'file_identity',
        'captured_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historic import source snapshots are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Historic import source snapshots cannot be deleted through the model.');
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

    /** @return BelongsTo<HistoricImportArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(HistoricImportArtifact::class, 'historic_import_artifact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_identity' => 'array',
            'captured_at' => 'immutable_datetime',
        ];
    }
}
