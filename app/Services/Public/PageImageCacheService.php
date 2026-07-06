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
        return Cache::rememberForever($this->cacheKey($page->id), function () use ($page): array {
            $page->loadMissing('media');

            /** @var Media|null $media */
            $media = $page->getFirstMedia('headings');

            return [
                'desktop' => $this->resolveHeadingImageUrl($page, $media, ['desktop', 'large'], 'large'),
                'mobile' => $this->resolveHeadingImageUrl($page, $media, ['mobile'], 'small'),
                'small' => $this->resolveHeadingImageUrl($page, $media, ['thumbnail', 'small'], 'small'),
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

        $publicPath = "images/headings/{$size}/{$page->slug}.webp";

        if (file_exists(public_path($publicPath))) {
            return asset($publicPath);
        }

        return null;
    }

    private function cacheKey(int $pageId): string
    {
        return "public_page_images_{$pageId}";
    }
}
