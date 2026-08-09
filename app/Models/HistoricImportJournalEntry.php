<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HistoricImportJournalEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'historic_import_operation_id',
        'historic_import_checkpoint_id',
        'sequence',
        'event',
        'disposition',
        'payload',
        'previous_entry_hash',
        'entry_hash',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historic import journal entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Historic import journal entries cannot be deleted through the model.');
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
            'disposition' => HistoricImportDisposition::class,
            'payload' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
