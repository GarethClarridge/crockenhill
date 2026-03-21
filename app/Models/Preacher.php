<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;

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
class Preacher extends Model implements Sitemapable
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

    /**
     * Get a list of active preachers with sermon counts for the public preachers index.
     *
     * Performance Optimization: Caches the preacher list and counts for 24 hours using flexible
     * cache to reduce redundant complex subqueries on every public listing request.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Preacher>
     */
    public static function getForPublicList(): \Illuminate\Database\Eloquent\Collection
    {
        return \Illuminate\Support\Facades\Cache::flexible('public_preacher_list', [86400, 172800], function () {
            return self::active()
                ->select(['id', 'name', 'slug'])
                ->withCount([
                    'sermons' => fn (Builder $query): Builder => $query->whereSermon(),
                ])
                ->orderByDesc('sermons_count')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Convert the preacher to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(): Url|string|array
    {
        $url = Url::create("/christ/sermons/preachers/{$this->slug}")
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.6);

        if ($this->updated_at) {
            $updatedAt = $this->updated_at;
            if ($updatedAt->year > 0) {
                $url->setLastModificationDate($updatedAt);
            }
        }

        return $url;
    }
}
