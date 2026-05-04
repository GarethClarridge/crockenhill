<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionType;
use Database\Factories\ChurchServiceItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

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
 * @property string|null $livestream_processing_id
 * @property int|null $livestream_service_section_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ChurchService $churchService
 * @property-read Song|null $song
 * @property-read Collection<int, ServiceSection> $serviceSections
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
    /** @use HasFactory<ChurchServiceItemFactory> */
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
        'livestream_processing_id',
        'livestream_service_section_id',
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

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $item = null, ?int $churchServiceId = null): array
    {
        $positionRule = ['required', 'integer', 'min:1'];
        $uniquePosition = Rule::unique('church_service_items', 'position')
            ->whereNull('deleted_at');

        if ($item) {
            $uniquePosition->ignore($item->id);
            $churchServiceId ??= $item->church_service_id;
        }

        if ($churchServiceId) {
            $uniquePosition->where('church_service_id', $churchServiceId);
        }

        $positionRule[] = $uniquePosition;

        return [
            'church_service_id' => ['required', 'integer', 'exists:church_services,id'],
            'position' => $positionRule,
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'section_type' => ['nullable', Rule::enum(ServiceSectionType::class)],
            'source' => ['nullable', Rule::enum(ChurchServiceItemSource::class)],
            'song_id' => ['nullable', 'integer', 'exists:songs,id'],
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
