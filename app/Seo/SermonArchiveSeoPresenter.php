<?php

declare(strict_types=1);

namespace App\Seo;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Presenters\SermonViewPresenter;
use App\Services\Public\PreacherListCache;
use App\Services\Public\SermonRepository;
use Illuminate\Support\Str;

class SermonArchiveSeoPresenter
{
    /**
     * @var array<int, ?string>
     */
    private array $memoizedPreacherNames = [];

    /**
     * @var array<int, ?string>
     */
    private array $memoizedPreacherImages = [];

    public function __construct(
        private readonly PreacherListCache $preacherListRepository,
        private readonly SermonRepository $sermonRepository,
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Generate SEO title based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function title(array $filters, int $page = 1): string
    {
        $base = 'Sermons';

        if (array_filter($filters)) {
            $parts = [];

            if ($filters['book']) {
                $parts[] = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
            }

            if ($filters['preacherId']) {
                $preacherName = $this->resolvePreacherName($filters['preacherId']);
                if ($preacherName) {
                    $parts[] = $preacherName;
                }
            }

            if ($filters['series']) {
                $parts[] = $filters['series'];
            }

            $base = implode(' | ', $parts).' | Sermons';
        }

        if ($page > 1) {
            return "{$base} (Page {$page})";
        }

        return $base;
    }

    /**
     * Generate SEO description based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function description(array $filters, int $page = 1): string
    {
        if (! array_filter($filters)) {
            $desc = 'Explore the sermon archive at Crockenhill Baptist Church. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series.';
        } else {
            $parts = [];

            if ($filters['book']) {
                $scripture = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
                $parts[] = "on {$scripture}";
            }

            if ($filters['preacherId']) {
                $preacherName = $this->resolvePreacherName($filters['preacherId']);
                if ($preacherName) {
                    $parts[] = "by {$preacherName}";
                }
            }

            if ($filters['series']) {
                $parts[] = "in the '{$filters['series']}' series";
            }

            $desc = 'Watch or listen to Bible-based sermons '.implode(' ', $parts).' from Crockenhill Baptist Church. Explore recent teaching from our morning and evening services.';
        }

        if ($page > 1) {
            return "{$desc} - Page {$page}";
        }

        return $desc;
    }

    /**
     * Generate canonical URL based on filters and page.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function canonical(array $filters, int $page = 1): string
    {
        $params = array_filter([
            'book' => $filters['book'],
            'chapter' => $filters['chapter'],
            'preacher' => $filters['preacherId'],
            'series' => $filters['series'],
            'page' => $page > 1 ? $page : null,
        ]);

        return route('sermons.index', $params);
    }

    /**
     * Generate SEO share image based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function image(array $filters): ?string
    {
        if ($filters['preacherId']) {
            $image = $this->resolvePreacherImage($filters['preacherId']);
            if ($image) {
                return $image;
            }
        }

        if ($filters['series']) {
            $latestInSeries = $this->sermonRepository->getSermonsBySeries($filters['series'])->first();
            if ($latestInSeries) {
                return $this->sermonViewPresenter->thumbnailUrl($latestInSeries);
            }
        }

        return null;
    }

    /**
     * Generate alt text for the SEO share image based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function imageAlt(array $filters, ?string $shareImage = null): string
    {
        if ($shareImage === null) {
            return 'Sermons at Crockenhill Baptist Church';
        }

        if ($filters['preacherId']) {
            $preacherName = $this->resolvePreacherName($filters['preacherId']);
            if ($preacherName) {
                return "Preacher: {$preacherName}";
            }
        }

        if ($filters['series']) {
            return "Sermon Series: {$filters['series']}";
        }

        return 'Sermons at Crockenhill Baptist Church';
    }

    /**
     * Title for a single preacher's archive page.
     */
    public function preacherTitle(Preacher $preacher): string
    {
        return 'Sermons by '.$preacher->name;
    }

    /**
     * Description for a single preacher's archive page.
     */
    public function preacherDescription(Preacher $preacher): string
    {
        return 'Browse all sermons preached by '.$preacher->name.' at Crockenhill Baptist Church.';
    }

    /**
     * Title for a single series' archive page.
     */
    public function seriesTitle(string $seriesName): string
    {
        return 'Sermon Series: '.$seriesName;
    }

    /**
     * Description for a single series' archive page.
     */
    public function seriesDescription(string $seriesName): string
    {
        return 'Browse all sermons in the "'.$seriesName.'" series from Crockenhill Baptist Church.';
    }

    /**
     * Title for a service-type archive page.
     */
    public function serviceTitle(SermonService $service, string $serviceSlug): string
    {
        return $this->serviceLabel($service, $serviceSlug).' Services';
    }

    /**
     * Description for a service-type archive page.
     */
    public function serviceDescription(SermonService $service, string $serviceSlug): string
    {
        $serviceLabel = $this->serviceLabel($service, $serviceSlug);

        return "Listen to recent {$serviceLabel} sermons from Crockenhill Baptist Church.";
    }

    /**
     * Human-readable label for a service type, distinct from SermonService::label()
     * (these are public page headings, so 'Sunday Morning' rather than 'Morning').
     */
    private function serviceLabel(SermonService $service, string $serviceSlug): string
    {
        return match ($service) {
            SermonService::Morning => 'Sunday Morning',
            SermonService::Evening => 'Sunday Evening',
            SermonService::Other => Str::title($serviceSlug),
        };
    }

    /**
     * Resolve a preacher's display name from their ID, or null if not found.
     *
     * Shared with the breadcrumb presenter so the filtered-archive trail and the
     * page title resolve the same name through one cached, memoized lookup.
     */
    public function preacherName(int $preacherId): ?string
    {
        return $this->resolvePreacherName($preacherId);
    }

    /**
     * Clear all internal memoization caches.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPreacherNames = [];
        $this->memoizedPreacherImages = [];
    }

    /**
     * Resolve a preacher name from ID, utilizing identity-based request-level memoization
     * and the cached public preacher collection to avoid redundant DB queries.
     *
     * Performance Optimization: Checks the cached 'public_preacher_list' first (which
     * is already loaded in most archive requests) before falling back to a direct
     * database find() for inactive/legacy preachers.
     */
    private function resolvePreacherName(int $preacherId): ?string
    {
        if (array_key_exists($preacherId, $this->memoizedPreacherNames)) {
            return $this->memoizedPreacherNames[$preacherId];
        }

        // Try the cached public collection first (it's memoized at the repository layer)
        $preacher = $this->preacherListRepository->forPublicList()->firstWhere('id', $preacherId);

        if ($preacher) {
            return $this->memoizedPreacherNames[$preacherId] = $preacher->name;
        }

        // Fallback to direct lookup for inactive preachers
        $preacher = Preacher::query()->find($preacherId);

        return $this->memoizedPreacherNames[$preacherId] = $preacher?->name;
    }

    /**
     * Resolve a preacher image from ID, utilizing identity-based request-level memoization.
     */
    private function resolvePreacherImage(int $preacherId): ?string
    {
        if (array_key_exists($preacherId, $this->memoizedPreacherImages)) {
            return $this->memoizedPreacherImages[$preacherId];
        }

        // Try the cached public collection first
        $preacher = $this->preacherListRepository->forPublicList()->firstWhere('id', $preacherId);

        if ($preacher) {
            return $this->memoizedPreacherImages[$preacherId] = $preacher->profile_image_url;
        }

        // Fallback to direct lookup
        $preacher = Preacher::query()->find($preacherId);

        return $this->memoizedPreacherImages[$preacherId] = $preacher?->profile_image_url;
    }
}
