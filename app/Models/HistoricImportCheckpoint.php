<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricImportCheckpointState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property HistoricImportCheckpointState $state
 * @property int $historic_import_operation_id
 * @property string $checkpoint_key
 * @property int $ordinal
 * @property string $membership_hash
 * @property list<string> $item_keys
 * @property int $forecast_seconds
 * @property string|null $runtime_fingerprint
 * @property int $accepted_cost_minor_units
 * @property CarbonInterface|null $deadline_at
 * @property CarbonInterface|null $admitted_at
 * @property CarbonInterface|null $settled_at
 * @property CarbonInterface|null $last_reconciled_at
 */
class HistoricImportCheckpoint extends Model
{
    /** @var list<string> */
    private const ImmutableMembership = [
        'historic_import_operation_id',
        'checkpoint_key',
        'ordinal',
        'membership_hash',
        'item_keys',
        'forecast_seconds',
        'accepted_cost_minor_units',
    ];

    protected $fillable = [
        'historic_import_operation_id',
        'checkpoint_key',
        'ordinal',
        'membership_hash',
        'item_keys',
        'forecast_seconds',
        'runtime_fingerprint',
        'accepted_cost_minor_units',
        'deadline_at',
        'state',
        'admitted_at',
        'settled_at',
        'last_reconciled_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $checkpoint): void {
            if ($checkpoint->isDirty(self::ImmutableMembership)) {
                throw new LogicException('Historic import checkpoint membership is immutable.');
            }

            if ($checkpoint->getOriginal('runtime_fingerprint') !== null && $checkpoint->isDirty('runtime_fingerprint')) {
                throw new LogicException('Historic import checkpoint runtime binding is immutable after admission.');
            }

            if ($checkpoint->getOriginal('deadline_at') !== null && $checkpoint->isDirty('deadline_at')) {
                throw new LogicException('Historic import checkpoint deadline is immutable after admission.');
            }
        });
    }

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
            'deadline_at' => 'immutable_datetime',
            'last_reconciled_at' => 'immutable_datetime',
            'accepted_cost_minor_units' => 'integer',
        ];
    }
}
