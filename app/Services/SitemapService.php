<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Support\Collection;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapService
{
    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    /**
     * Generate sitemap.xml
     *
     * Performance Optimization: Limits retrieved columns for dynamic models and eager loads
     * required relationships to prevent N+1 queries. Large text fields like body, markdown,
     * and transcript are excluded to reduce memory usage during the generation of large collections.
     */
    public function generate(): bool
    {
        $sitemapPath = $this->getFilePath();

        $sitemap = Sitemap::create()
            // Static high-priority URLs
            ->add(Url::create('/')->setPriority(1.0)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/christ')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/church')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/community')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/calendar')->setPriority(0.5)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/christ/sermons')->setPriority(0.8)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY));

        if ($this->exposurePolicy->childrensTalksArePublic()) {
            $sitemap->add(
                Url::create('/christ/childrens-corner')
                    ->setPriority(0.7)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
            );
        }

        /** @var Collection<int, Sermon> $sermons */
        $sermons = Sermon::query()
            ->select(['id', 'title', 'date', 'slug', 'updated_at', 'video_file_path', 'thumbnail_file_path', 'thumbnail_metadata', 'summary', 'duration', 'preacher', 'preacher_id', 'reference', 'series', 'meta_description', 'content_type'])
            ->with('preacherProfile:id,name,slug')
            ->get()
            ->filter(fn (Sermon $sermon): bool => $this->exposurePolicy->shouldIncludeInSitemap($sermon))
            ->values();

        $sitemap
            // Dynamic content via Sitemapable models
            // Eager load relationships to prevent N+1 queries during sitemap generation
            ->add($sermons)
            ->add(
                Page::query()
                    ->select(['id', 'slug', 'area', 'updated_at', 'description', 'heading'])
                    /**
                     * Performance Optimization: Only eager load 'media' (needed for images),
                     * and remove 'meeting' as it is not utilized in sitemap generation.
                     */
                    ->with(['media'])
                    ->where('admin', 'no')
                    ->get()
            )
            ->add(
                Meeting::query()
                    /**
                     * Performance Optimization: Only select columns required for sitemap generation
                     * to reduce memory usage.
                     */
                    ->select(['id', 'slug', 'updated_at'])
                    ->get()
            )
            ->writeToFile($sitemapPath);

        return true;
    }

    public function getFilePath(): string
    {
        // In parallel test runs each worker gets a unique token, preventing race conditions
        // when multiple processes write to the same file simultaneously.
        $token = config('app.test_token');
        if (app()->environment('testing') && $token !== null) {
            return public_path("sitemap-test-{$token}.xml");
        }

        return public_path('sitemap.xml');
    }

    public function shouldRegenerate(): bool
    {
        return ! file_exists($this->getFilePath());
    }
}
