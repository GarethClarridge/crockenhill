<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One public destination claimed by one release attempt.
 *
 * `destination_identity` is globally unique, so the claim itself is what stops
 * two attempts from believing they own the same public path. A claim exists
 * before any byte is written, which is what makes the ownership durable rather
 * than inferred from what happens to be at the destination.
 *
 * @property int $id
 * @property int $historic_import_release_attempt_id
 * @property string $record_type
 * @property int $record_id
 * @property string $source_disk
 * @property string $source_path
 * @property string $destination_disk
 * @property string $destination_path
 * @property string $destination_identity
 * @property int|null $size
 * @property string|null $sha256
 * @property string $state
 * @property string|null $create_result
 * @property string|null $provider_receipt
 * @property string|null $provider_version_id
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $compensated_at
 * @property-read HistoricImportReleaseAttempt|null $attempt
 *
 * Delete alongside the release ledger after the accepted public release and
 * rollback observation window (G9/WP10).
 */
class HistoricImportReleaseAsset extends Model
{
    public const string StateClaimed = 'claimed';

    /** Written by this attempt and confirmed to hold the source bytes. */
    public const string StateCreatedVerified = 'created_verified';

    /**
     * Already at the destination with identical bytes when this attempt arrived.
     * Never cleanup-owned: another writer put it there and may be advertising it.
     */
    public const string StatePreexistingVerified = 'preexisting_verified';

    /** The records that advertise it are public. */
    public const string StatePublished = 'published';

    /** Created by a failed attempt and retained, because it cannot be taken back. */
    public const string StateOrphaned = 'orphaned';

    /** Claimed but never written. Nothing to retain and nothing to delete. */
    public const string StateAbandoned = 'abandoned';

    public const string TypeSermon = 'sermon';

    public const string TypeSongVideo = 'song_video';

    protected $fillable = [
        'historic_import_release_attempt_id',
        'record_type',
        'record_id',
        'source_disk',
        'source_path',
        'destination_disk',
        'destination_path',
        'destination_identity',
        'size',
        'sha256',
        'state',
        'create_result',
        'provider_receipt',
        'provider_version_id',
        'verified_at',
        'published_at',
        'compensated_at',
    ];

    /** The globally unique name of a public destination. */
    public static function identityFor(string $disk, string $path): string
    {
        return hash('sha256', $disk.'|'.$path);
    }

    /** @return BelongsTo<HistoricImportReleaseAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(HistoricImportReleaseAttempt::class, 'historic_import_release_attempt_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'record_id' => 'integer',
            'verified_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'compensated_at' => 'immutable_datetime',
        ];
    }
}
