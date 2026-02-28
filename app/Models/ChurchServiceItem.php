<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $title
 * @property string|null $source_title
 * @property string|null $openlp_search_title
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read ChurchService $churchService
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
        'title',
        'source_title',
        'openlp_search_title',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ChurchService, $this>
     */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /**
     * @return HasMany<ServiceSection, $this>
     */
    public function serviceSections(): HasMany
    {
        return $this->hasMany(ServiceSection::class);
    }
}
