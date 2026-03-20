<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PageImagePresenter
{
    public function headingImageUrl(Page $page): ?string
    {
        return $this->resolveHeadingImageUrl($page, ['desktop', 'large'], 'large');
    }

    public function headingImageTabletUrl(Page $page): ?string
    {
        return $this->resolveHeadingImageUrl($page, ['tablet'], 'large');
    }

    public function headingImageMobileUrl(Page $page): ?string
    {
        return $this->resolveHeadingImageUrl($page, ['mobile'], 'small');
    }

    public function headingImageSmallUrl(Page $page): ?string
    {
        return $this->resolveHeadingImageUrl($page, ['thumbnail', 'small'], 'small');
    }

    /**
     * @param  array<int, string>  $conversions
     */
    private function resolveHeadingImageUrl(Page $page, array $conversions, string $size): ?string
    {
        /** @var Media|null $media */
        $media = $page->getFirstMedia('headings');

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
}
