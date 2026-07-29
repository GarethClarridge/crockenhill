<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceProposalStatus;
use Database\Factories\ChurchServiceMergeProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchServiceMergeProposal extends Model
{
    /** @use HasFactory<ChurchServiceMergeProposalFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => ChurchServiceProposalStatus::Pending->value,
    ];

    protected $fillable = [
        'church_service_id',
        'trigger_source_record_id',
        'base_canonical_revision',
        'base_canonical_hash',
        'included_source_hashes',
        'proposed_items',
        'proposed_hash',
        'field_decisions',
        'conflicts',
        'status',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'included_source_hashes' => 'array',
            'proposed_items' => 'array',
            'field_decisions' => 'array',
            'conflicts' => 'array',
            'status' => ChurchServiceProposalStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChurchService, $this> */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /** @return BelongsTo<ChurchServiceSourceRecord, $this> */
    public function triggerSourceRecord(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceSourceRecord::class, 'trigger_source_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
