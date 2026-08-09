<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $state
 * @property int $attempts
 * @property CarbonInterface|null $settled_at
 * @property string|null $error_fingerprint
 */
class HistoricImportNestedJob extends Model
{
    protected $fillable = [
        'historic_import_operation_id',
        'media_processing_log_id',
        'job_key',
        'job_type',
        'state',
        'attempts',
        'error_fingerprint',
        'dispatched_at',
        'settled_at',
    ];

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
            'dispatched_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
