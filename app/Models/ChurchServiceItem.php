<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceItemSource;
use App\Enums\MediaType;
use App\Enums\ServiceSectionType;
use Database\Factories\ChurchServiceItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property-read ServiceSection|null $livestreamServiceSection
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
            'id' => 'integer',
            'church_service_id' => 'integer',
            'position' => 'integer',
            'section_type' => ServiceSectionType::class,
            'source' => ChurchServiceItemSource::class,
            'song_id' => 'integer',
            'livestream_service_section_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function sourceTitle(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function openlpSearchTitle(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $item = null, ?int $churchServiceId = null): array
    {
        return [
            'church_service_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'exists:church_services,id'],
            'position' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'section_type' => ['nullable', Rule::enum(ServiceSectionType::class)],
            'source' => ['nullable', Rule::enum(ChurchServiceItemSource::class)],
            'source_title' => ['nullable', 'string', 'max:255'],
            'openlp_search_title' => ['nullable', 'string', 'max:255'],
            'song_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:songs,id'],
            'metadata' => ['nullable', 'array'],
            'livestream_processing_id' => [
                'nullable',
                'uuid',
                Rule::exists('media_processing_logs', 'processing_id')
                    ->where('processing_type', MediaType::Livestream->value),
            ],
            'livestream_service_section_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:service_sections,id'],
        ];
    }

    public function semanticSectionType(): ServiceSectionType
    {
        if ($this->section_type instanceof ServiceSectionType) {
            return $this->section_type;
        }

        return match (strtolower($this->type)) {
            'songs' => ServiceSectionType::Song,
            'bibles' => ServiceSectionType::BibleReading,
            default => ServiceSectionType::inferFromTitle($this->title),
        };
    }

    /**
     * Every independent source that contributed evidence for this item.
     *
     * @return list<ChurchServiceItemSource>
     */
    public function provenanceSources(): array
    {
        $evidence = $this->metadata['source_evidence'] ?? null;

        if (! is_array($evidence)) {
            return $this->source instanceof ChurchServiceItemSource ? [$this->source] : [];
        }

        $sources = [];

        foreach (ChurchServiceItemSource::cases() as $source) {
            if (array_key_exists($source->value, $evidence)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    public function provenanceLabel(): string
    {
        return collect($this->provenanceSources())
            ->map(fn (ChurchServiceItemSource $source): string => $source->label())
            ->implode(' + ');
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
     * @return BelongsTo<ServiceSection, $this>
     */
    public function livestreamServiceSection(): BelongsTo
    {
        return $this->belongsTo(ServiceSection::class, 'livestream_service_section_id');
    }

    /**
     * @return HasMany<ServiceSection, $this>
     */
    public function serviceSections(): HasMany
    {
        return $this->hasMany(ServiceSection::class);
    }
}
