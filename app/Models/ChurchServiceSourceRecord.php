<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceSource;
use Database\Factories\ChurchServiceSourceRecordFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ChurchServiceSource $source
 * @property string $source_key
 * @property string $revision_hash
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
