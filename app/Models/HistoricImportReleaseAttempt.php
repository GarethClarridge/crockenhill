<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to publish one signed release batch.
 *
 * The attempt is the owner: a second process with the same authorisation and
 * membership either observes this row completed and does nothing, refuses
 * because its lease is live, or takes it through an explicit reconciliation
 * once the lease has expired. It never creates a second owner, because
 * `historic_release_attempt_batch_unique` will not let it.
 *
 * @property int $id
 * @property string $attempt_id
 * @property int $historic_import_operation_id
 * @property string|null $authorisation_id
 * @property string|null $batch_key
 * @property string $authorisation_hash
 * @property string $membership_hash
 * @property string $state
 * @property string $lease_token
 * @property CarbonImmutable $lease_expires_at
 * @property string|null $failure_summary
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property-read HistoricImportOperation|null $operation
 *
 * Delete alongside the release ledger after the accepted public release and
 * rollback observation window (G9/WP10).
 */
class HistoricImportReleaseAttempt extends Model
{
    /** Claimed, working, and not yet finished either way. */
    public const string StateClaimed = 'claimed';

    /** Every asset verified and every record published. Terminal and successful. */
    public const string StateCompleted = 'completed';

    /** Finished unsuccessfully, with no object this attempt created left unowned. */
    public const string StateFailed = 'failed';

    /**
     * Finished unsuccessfully having created at least one final object it cannot
     * take back, because exact-version deletion does not exist here. The objects
     * are retained and reconciled by a human; the attempt never completes.
     */
    public const string StateOrphaned = 'orphaned';

    protected $fillable = [
        'attempt_id',
        'historic_import_operation_id',
        'authorisation_id',
        'batch_key',
        'authorisation_hash',
        'membership_hash',
        'state',
        'lease_token',
        'lease_expires_at',
        'failure_summary',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    /** @return BelongsTo<HistoricImportOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(HistoricImportOperation::class, 'historic_import_operation_id');
    }

    /** @return HasMany<HistoricImportReleaseAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(HistoricImportReleaseAsset::class, 'historic_import_release_attempt_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->state, [self::StateCompleted, self::StateFailed, self::StateOrphaned], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
