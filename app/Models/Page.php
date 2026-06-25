<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageArea;
use App\Sitemap\PageSitemapPresenter;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
 * @property string $admin
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
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            if (filled($page->description)) {
                return;
            }

            $heading = trim($page->heading);

            $page->description = $heading !== '' ? $heading : 'No description provided.';
        });
    }

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
        'admin',
        'navigation',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'navigation' => 'boolean',
            'area' => PageArea::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function heading(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $page = null, ?string $area = null): array
    {
        $slugRule = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];

        $uniqueRule = Rule::unique('pages', 'slug');
        if ($page) {
            $uniqueRule->ignore($page->id);
        }
        if ($area) {
            $uniqueRule->where('area', $area);
        }

        $slugRule[] = $uniqueRule;

        return [
            'heading' => ['required', 'string', 'max:255'],
            'slug' => $slugRule,
            'area' => ['required', Rule::enum(PageArea::class)],
            'description' => ['required', 'string', 'max:155'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
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
     *
     * @return Attribute<?string, never>
     */
    protected function route(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if ($this->slug !== '') {
                    return '/'.trim($this->area->value, '/').'/'.trim($this->slug, '/');
                }

                return null;
            }
        );
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
     * Determine whether this page is restricted to administrators.
     */
    public function isAdminOnly(): bool
    {
        return $this->admin === 'yes';
    }

    /**
     * Get the meeting associated with this page.
     *
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
     *
     * @return Attribute<string, never>
     */
    protected function metaDescription(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $description = trim(strip_tags($this->description ?? ''));

                if (empty($description)) {
                    $description = $this->heading;
                }

                return Str::limit($description, 155);
            }
        )->shouldCache();
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
     * Convert the page to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(): Url|string|array
    {
        return app(PageSitemapPresenter::class)->toSitemapTag($this);
    }
}
