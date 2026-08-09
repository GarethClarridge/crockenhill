<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportCheckpointState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property HistoricImportCheckpointState $state
 */
class HistoricImportCheckpoint extends Model
{
    protected $fillable = [
        'historic_import_operation_id',
        'checkpoint_key',
        'ordinal',
        'membership_hash',
        'item_keys',
        'forecast_seconds',
        'state',
        'admitted_at',
        'settled_at',
    ];

    /** @return BelongsTo<HistoricImportOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(HistoricImportOperation::class, 'historic_import_operation_id');
    }

    /** @return HasMany<HistoricImportArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(HistoricImportArtifact::class);
    }

    /** @return HasMany<HistoricImportJournalEntry, $this> */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(HistoricImportJournalEntry::class)->orderBy('sequence');
    }

    /** @return HasMany<HistoricImportItemOutcome, $this> */
    public function itemOutcomes(): HasMany
    {
        return $this->hasMany(HistoricImportItemOutcome::class);
    }

    public function transitionTo(HistoricImportCheckpointState $next): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new LogicException("Historic import checkpoint cannot transition from {$this->state->value} to {$next->value}.");
        }

        $this->state = $next;
        $this->save();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_keys' => 'array',
            'state' => HistoricImportCheckpointState::class,
            'admitted_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
