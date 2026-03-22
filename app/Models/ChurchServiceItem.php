<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $church_service_id
 * @property int $position
 * @property string $type
 * @property ServiceSectionType|null $section_type
 * @property ChurchServiceItemSource|null $source
 * @property string $title
 * @property string|null $source_title
 * @property string|null $openlp_search_title
 * @property int|null $song_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read ChurchService $churchService
 * @property-read Song|null $song
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceSection> $serviceSections
 *
 * @method static \Database\Factories\ChurchServiceItemFactory factory(...$parameters)
 * @method static Builder<ChurchServiceItem> newModelQuery()
 * @method static Builder<ChurchServiceItem> newQuery()
 * @method static Builder<ChurchServiceItem> query()
 *
 * @mixin \Eloquent
 */
class ChurchServiceItem extends Model
{
    /** @use HasFactory<\Database\Factories\ChurchServiceItemFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'church_service_id',
        'position',
        'type',
        'section_type',
        'source',
        'title',
        'source_title',
        'openlp_search_title',
        'song_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'section_type' => ServiceSectionType::class,
            'source' => ChurchServiceItemSource::class,
            'song_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function semanticSectionType(): ServiceSectionType
    {
        if ($this->section_type instanceof ServiceSectionType) {
            return $this->section_type;
        }

        $explicitMetadataType = $this->explicitMetadataSectionType();

        if ($explicitMetadataType instanceof ServiceSectionType) {
            return $explicitMetadataType;
        }

        return match (strtolower($this->type)) {
            'songs' => ServiceSectionType::SONG,
            'bibles' => ServiceSectionType::BIBLE_READING,
            default => ServiceSectionType::inferFromTitle($this->title),
        };
    }

    public function explicitMetadataSectionType(): ?ServiceSectionType
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $metadataType = $metadata['section_type'] ?? null;

        return is_string($metadataType)
            ? ServiceSectionType::tryFrom($metadataType)
            : null;
    }

    /**
     * @return BelongsTo<ChurchService, $this>
     */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /**
     * @return BelongsTo<Song, $this>
     */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /**
     * @return HasMany<ServiceSection, $this>
     */
    public function serviceSections(): HasMany
    {
        return $this->hasMany(ServiceSection::class);
    }
}
