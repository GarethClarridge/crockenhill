<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportOperationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property HistoricImportOperationState $state
 */
class HistoricImportOperation extends Model
{
    protected $fillable = [
        'operation_id',
        'binding_hash',
        'batch_key',
        'manifest_hashes',
        'plan_hash',
        'target_fingerprint',
        'state',
        'accepted_deadline',
    ];

    /** @return HasMany<HistoricImportCheckpoint, $this> */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(HistoricImportCheckpoint::class);
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

    /** @return HasMany<HistoricImportSourceSnapshot, $this> */
    public function sourceSnapshots(): HasMany
    {
        return $this->hasMany(HistoricImportSourceSnapshot::class);
    }

    /** @return HasMany<HistoricImportItemOutcome, $this> */
    public function itemOutcomes(): HasMany
    {
        return $this->hasMany(HistoricImportItemOutcome::class);
    }

    public function transitionTo(HistoricImportOperationState $next): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new LogicException("Historic import operation cannot transition from {$this->state->value} to {$next->value}.");
        }

        $this->state = $next;
        $this->save();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manifest_hashes' => 'array',
            'state' => HistoricImportOperationState::class,
            'accepted_deadline' => 'immutable_datetime',
        ];
    }
}
