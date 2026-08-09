<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportArtifactKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HistoricImportArtifact extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'historic_import_operation_id',
        'historic_import_checkpoint_id',
        'artifact_key',
        'kind',
        'storage_disk',
        'relative_path',
        'sha256',
        'byte_size',
        'encrypted',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historic import artifacts are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Historic import artifacts cannot be deleted through the model.');
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
        return [
            'kind' => HistoricImportArtifactKind::class,
            'encrypted' => 'boolean',
        ];
    }
}
