<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceSource;
use App\Support\ChurchServiceSourceKey;
use Database\Factories\ChurchServiceSourceRecordFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ChurchServiceSource $source
 * @property string $source_key
 * @property string $source_key_hash
 * @property string $revision_hash
 * @property array<string, mixed> $processing_fingerprint
 * @property array<string, mixed>|null $service_content
 * @property Carbon|null $captured_at
 * @property-read Collection<int, ChurchServiceItemAssertion> $assertions
 */
class ChurchServiceSourceRecord extends Model
{
    /** @use HasFactory<ChurchServiceSourceRecordFactory> */
    use HasFactory;

    protected $attributes = [
        'payload_complete' => true,
    ];

    protected $fillable = [
        'church_service_id',
        'source',
        'source_key',
        'source_key_hash',
        'revision_hash',
        'input_hash',
        'supersedes_id',
        'batch_hash',
        'processing_fingerprint',
        'service_content',
        'payload_complete',
        'captured_at',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            $record->source_key = ChurchServiceSourceKey::canonical($record->source_key);
            $record->source_key_hash = ChurchServiceSourceKey::identity($record->source_key);
        });
    }

    protected function casts(): array
    {
        return [
            'source' => ChurchServiceSource::class,
            'processing_fingerprint' => 'array',
            'service_content' => 'array',
            'payload_complete' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChurchService, $this> */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /** @return BelongsTo<self, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasOne<self, $this> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ChurchServiceItemAssertion, $this> */
    public function assertions(): HasMany
    {
        return $this->hasMany(ChurchServiceItemAssertion::class, 'source_record_id');
    }

    /** @return HasMany<ChurchServiceMergeProposal, $this> */
    public function triggeredProposals(): HasMany
    {
        return $this->hasMany(ChurchServiceMergeProposal::class, 'trigger_source_record_id');
    }
}
