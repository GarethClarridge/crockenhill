<?php

namespace App\Models;

use App\Enums\PageArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory; // For scope return types
use Illuminate\Database\Eloquent\Model; // For type hinting Carbon instances
use Illuminate\Support\Carbon; // Added Enum import
use Illuminate\Support\Str;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;

/**
 * App\Models\Page
 *
 * @property int $id
 * @property string $slug
 * @property string $heading
 * @property string $title
 * @property string $content
 * @property string $description
 * @property PageArea $area
 * @property string $body
 * @property ?string $markdown
 * @property bool $navigation
 * @property bool $published
 * @property ?string $image_url
 * @property ?int $sort_order
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?string $route
 *
 * @method static \Database\Factories\PageFactory factory(...$parameters)
 * @method static Builder|Page newModelQuery()
 * @method static Builder|Page newQuery()
 * @method static Builder|Page query()
 * @method static Builder|Page inArea(string $area)
 * @method static Builder|Page isNavigation(bool $isNavigation = true)
 *
 * @mixin \Eloquent
 */
class Page extends Model implements Sitemapable
{
    use HasFactory;

    protected $table = 'pages';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'heading',
        'slug',
        'area',
        'markdown',
        'body',
        'description',
        'navigation',
        // 'admin' field from migration is not included here, assuming it's not mass assignable
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'navigation' => 'boolean',
        'area' => PageArea::class,
        // 'admin' => 'string', // Or a custom cast if 'yes'/'no' needs specific handling
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the page's full route path.
     */
    public function getRouteAttribute(): ?string
    {
        if ($this->slug) {
            return '/'.trim($this->area->value, '/').'/'.trim($this->slug, '/');
        }

        return null; // Or some default/error handling if slug is missing
    }

    public function scopeInArea(Builder $query, string|PageArea $area): Builder
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;

        return $query->where('area', $areaValue);
    }

    public function scopeIsNavigation(Builder $query, bool $isNavigation = true): Builder
    {
        return $query->where('navigation', $isNavigation);
    }

    /**
     * Get the SEO meta description for the page.
     * Truncates the description field to 155 characters (plus ellipsis if truncated).
     */
    public function getMetaDescriptionAttribute(): string
    {
        $description = trim(strip_tags($this->description ?? ''));

        if (empty($description)) {
            $description = $this->heading;
        }

        return Str::limit($description, 155);
    }

    /**
     * Convert the page to a sitemap tag.
     */
    public function toSitemapTag(): Url | string | array
    {
        $url = Url::create($this->route)
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.7);

        if ($this->updated_at) {
            $url->setLastModificationDate($this->updated_at);
        }

        return $url;
    }
}
