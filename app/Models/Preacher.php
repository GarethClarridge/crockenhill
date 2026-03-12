<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Preacher
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $image_path
 * @property ?string $bio
 * @property bool $is_active
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 *
 * @method static \Database\Factories\PreacherFactory factory(...$parameters)
 * @method static Builder|Preacher newModelQuery()
 * @method static Builder|Preacher newQuery()
 * @method static Builder|Preacher query()
 * @method static Builder|Preacher active()
 *
 * @mixin \Eloquent
 */
class Preacher extends Model
{
    /** @use HasFactory<\Database\Factories\PreacherFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'image_path',
        'bio',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Sermon, $this>
     */
    public function sermons(): HasMany
    {
        return $this->hasMany(Sermon::class);
    }

    /**
     * @return HasMany<PreacherAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PreacherAlias::class);
    }

    /**
     * @return HasMany<SpeakerProfile, $this>
     */
    public function speakerProfiles(): HasMany
    {
        return $this->hasMany(SpeakerProfile::class);
    }

    /**
     * @param  Builder<Preacher>  $query
     * @return Builder<Preacher>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get a list of active preachers for admin dropdowns.
     *
     * Performance Optimization: Caches the preacher list for 24 hours using flexible cache
     * to reduce redundant DB queries in the admin interface.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function getForAdminList(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\Cache::flexible('admin_preacher_list', [86400, 172800], function () {
            return self::active()->orderBy('name')->pluck('name', 'id');
        });
    }
}
