<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ServiceSectionType;
use Database\Factories\ChurchServiceItemAssertionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchServiceItemAssertion extends Model
{
    /** @use HasFactory<ChurchServiceItemAssertionFactory> */
    use HasFactory;

    protected $fillable = [
        'source_record_id',
        'assertion_key',
        'source_position',
        'evidence_kind',
        'type',
        'section_type',
        'title',
        'source_title',
        'normalized_title',
        'song_id',
        'song_canonical_key',
        'scripture_reference',
        'normalized_scripture_key',
        'start_seconds',
        'end_seconds',
        'confidence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'evidence_kind' => ChurchServiceEvidenceKind::class,
            'section_type' => ServiceSectionType::class,
            'confidence' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ChurchServiceSourceRecord, $this> */
    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceSourceRecord::class, 'source_record_id');
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return HasMany<ChurchServiceReviewDecision, $this> */
    public function reviewDecisions(): HasMany
    {
        return $this->hasMany(ChurchServiceReviewDecision::class, 'selected_assertion_id');
    }
}
