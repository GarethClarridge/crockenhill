<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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
 * @property ?int $sort_order
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?string $route
 * @property-read Meeting|null $meeting
 *
 * @method static \Database\Factories\PageFactory factory(...$parameters)
 * @method static Builder|Page newModelQuery()
 * @method static Builder|Page newQuery()
 * @method static Builder|Page query()
 * @method static Builder|Page inArea(string $area)
 * @method static Builder|Page isNavigation(bool $isNavigation = true)
 * @method static Builder|Page public()
 *
 * @mixin \Eloquent
 */
class Page extends Model implements HasMedia, Sitemapable
{
    /** @use HasFactory<\Database\Factories\PageFactory> */
    use HasFactory;

    use InteractsWithMedia;

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
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'navigation' => 'boolean',
            'area' => PageArea::class,
        ];
    }

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

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeInArea(Builder $query, string|PageArea $area): Builder
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;

        return $query->where('area', $areaValue);
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeIsNavigation(Builder $query, bool $isNavigation = true): Builder
    {
        return $query->where('navigation', $isNavigation);
    }

    /**
     * Scope to only publicly accessible pages (excludes admin-only pages).
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('admin', 'no');
    }

    /**
     * Get the meeting associated with this page.
     */
    /**
     * @return HasOne<Meeting, $this>
     */
    public function meeting(): HasOne
    {
        return $this->hasOne(Meeting::class);
    }

    /**
     * Check if the page has an associated meeting.
     */
    public function hasMeeting(): bool
    {
        return $this->meeting()->exists();
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
     * Register media collections for the page.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('headings')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Register media conversions for responsive images.
     *
     * Conversions are optimized for different device sizes:
     * - desktop: 1920px wide for full-width hero images on desktop
     * - tablet: 1024px wide for tablets and smaller desktops
     * - mobile: 640px wide for mobile devices
     * - thumbnail: 300px wide for page cards and previews
     *
     * All conversions use WebP format for optimal performance.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Desktop - full width hero images (1920px is common max viewport)
        $this->addMediaConversion('desktop')
            ->width(1920)
            ->height(960)
            ->sharpen(10)
            ->format('webp')
            ->quality(85)
            ->nonQueued();

        // Tablet - for tablets and smaller desktops
        $this->addMediaConversion('tablet')
            ->width(1024)
            ->height(512)
            ->sharpen(10)
            ->format('webp')
            ->quality(85)
            ->nonQueued();

        // Mobile - for mobile devices
        $this->addMediaConversion('mobile')
            ->width(640)
            ->height(320)
            ->sharpen(10)
            ->format('webp')
            ->quality(80)
            ->nonQueued();

        // Thumbnail - for page cards and previews
        $this->addMediaConversion('thumbnail')
            ->width(300)
            ->height(200)
            ->sharpen(10)
            ->format('webp')
            ->quality(80)
            ->nonQueued();

        // Legacy conversions for backwards compatibility
        $this->addMediaConversion('large')
            ->width(1920)
            ->height(960)
            ->sharpen(10)
            ->format('webp')
            ->quality(85)
            ->nonQueued();

        $this->addMediaConversion('small')
            ->width(300)
            ->height(200)
            ->sharpen(10)
            ->format('webp')
            ->quality(80)
            ->nonQueued();
    }

    /**
     * Check if the page has a heading image (Media Library or new storage).
     */
    public function hasImage(): bool
    {
        if ($this->getFirstMedia('headings')) {
            return true;
        }

        return Storage::disk('public')->exists("pages/headings/large/{$this->slug}.webp");
    }

    /**
     * Get responsive image srcset for the heading image.
     *
     * Returns a srcset string suitable for use in an img tag's srcset attribute.
     */
    public function getHeadingImageSrcsetAttribute(): ?string
    {
        $media = $this->getFirstMedia('headings');

        if (! $media) {
            return null;
        }

        $srcset = [];

        if ($media->hasGeneratedConversion('mobile')) {
            $srcset[] = $media->getUrl('mobile').' 640w';
        }
        if ($media->hasGeneratedConversion('tablet')) {
            $srcset[] = $media->getUrl('tablet').' 1024w';
        }
        if ($media->hasGeneratedConversion('desktop')) {
            $srcset[] = $media->getUrl('desktop').' 1920w';
        }

        return ! empty($srcset) ? implode(', ', $srcset) : null;
    }

    /**
     * Convert the page to a sitemap tag.
     */
    /**
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(): Url|string|array
    {
        if (! is_string($this->route) || $this->route === '') {
            return [];
        }

        $url = Url::create($this->route)
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.7);

        if ($this->updated_at) {
            $updatedAt = $this->updated_at;
            if ($updatedAt->year > 0) {
                $url->setLastModificationDate($updatedAt);
            }
        }

        return $url;
    }
}
