<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PageImageCacheService
{
    /**
     * @return array{desktop: ?string, mobile: ?string, small: ?string, tablet: ?string}
     */
    public function get(Page $page): array
    {
        return Cache::flexible($this->cacheKey($page->id), [300, 86400], function () use ($page): array {
            $page->loadMissing('media');

            /** @var Media|null $media */
            $media = $page->getFirstMedia('headings');

            return [
                'desktop' => $this->resolveHeadingImageUrl($page, $media, ['desktop'], 'large'),
                'mobile' => $this->resolveHeadingImageUrl($page, $media, ['mobile'], 'small'),
                'small' => $this->resolveHeadingImageUrl($page, $media, ['thumbnail'], 'small'),
                'tablet' => $this->resolveHeadingImageUrl($page, $media, ['tablet'], 'large'),
            ];
        });
    }

    public function forget(Page|int $page): void
    {
        $pageId = $page instanceof Page ? $page->id : $page;

        Cache::forget($this->cacheKey($pageId));
    }

    /**
     * Every name in $conversions must be registered in Page::registerMediaConversions() —
     * Media::getUrl() throws InvalidConversion for unregistered names even when the
     * conversion file was generated under a registration that has since been removed.
     *
     * @param  list<string>  $conversions
     */
    private function resolveHeadingImageUrl(Page $page, ?Media $media, array $conversions, string $size): ?string
    {
        if ($media instanceof Media) {
            foreach ($conversions as $conversion) {
                if ($media->hasGeneratedConversion($conversion)) {
                    return $media->getUrl($conversion);
                }
            }

            return $media->getUrl();
        }

        $storagePath = "pages/headings/{$size}/{$page->slug}.webp";

        if (Storage::disk('public')->exists($storagePath)) {
            return Storage::disk('public')->url($storagePath);
        }

        return null;
    }

    private function cacheKey(int $pageId): string
    {
        return "public_page_images_{$pageId}";
    }
}
