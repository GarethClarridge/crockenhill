<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportOperationState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property HistoricImportOperationState $state
 * @property string $operation_id
 * @property string $binding_hash
 * @property string $target_fingerprint
 * @property string $notification_mode
 * @property int $max_cost_minor_units
 * @property CarbonInterface|null $accepted_deadline
 */
class HistoricImportOperation extends Model
{
    /** @var list<string> */
    private const ImmutableBindings = [
        'operation_id',
        'binding_hash',
        'batch_key',
        'manifest_hashes',
        'plan_hash',
        'target_fingerprint',
        'notification_mode',
        'max_cost_minor_units',
    ];

    protected $fillable = [
        'operation_id',
        'binding_hash',
        'batch_key',
        'manifest_hashes',
        'plan_hash',
        'target_fingerprint',
        'notification_mode',
        'max_cost_minor_units',
        'state',
        'accepted_deadline',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $operation): void {
            if ($operation->isDirty(self::ImmutableBindings)) {
                throw new LogicException('Historic import operation bindings are immutable.');
            }

            if ($operation->getOriginal('state') !== HistoricImportOperationState::Planned->value
                && $operation->isDirty('accepted_deadline')) {
                throw new LogicException('Historic import accepted deadline is immutable after operation admission.');
            }
        });
    }

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

    /** @return HasMany<HistoricImportUsageEntry, $this> */
    public function usageEntries(): HasMany
    {
        return $this->hasMany(HistoricImportUsageEntry::class);
    }

    /** @return HasMany<HistoricImportAlert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(HistoricImportAlert::class);
    }

    /** @return HasMany<HistoricImportAssetTransfer, $this> */
    public function assetTransfers(): HasMany
    {
        return $this->hasMany(HistoricImportAssetTransfer::class);
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
            'max_cost_minor_units' => 'integer',
        ];
    }
}
