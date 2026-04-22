<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\MeetingSitemapPresenter;
use App\Presenters\PageSitemapPresenter;
use App\Presenters\PreacherSitemapPresenter;
use App\Presenters\SermonSitemapPresenter;
use App\Repositories\SermonRepository;
use Illuminate\Support\Str;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapService
{
    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonRepository $sermonRepository,
        private readonly PageSitemapPresenter $pageSitemapPresenter,
        private readonly SermonSitemapPresenter $sermonSitemapPresenter,
        private readonly MeetingSitemapPresenter $meetingSitemapPresenter,
        private readonly PreacherSitemapPresenter $preacherSitemapPresenter,
    ) {}

    /**
     * Generate sitemap.xml
     *
     * Performance Optimization: Limits retrieved columns for dynamic models and eager loads
     * required relationships to prevent N+1 queries. Large text fields like body, markdown,
     * and transcript are excluded to reduce memory usage. For sermons, 'thumbnail_metadata'
     * is excluded as it is not utilized in sitemap generation.
     */
    public function generate(): bool
    {
        $sitemapPath = $this->getFilePath();

        $sitemap = Sitemap::create()
            // Static high-priority URLs
            ->add(Url::create('/')
                ->setPriority(1.0)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/homepage/may2024wide.webp'), 'Crockenhill Baptist Church'))
            ->add(Url::create('/christ')
                ->setPriority(0.9)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/homepage/may2024wide.webp'), 'Learn about Jesus Christ at Crockenhill Baptist Church'))
            ->add(Url::create('/christmas')
                ->setPriority(0.8)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                ->addImage(asset('/images/homepage/christmas2023.webp'), 'Christmas at Crockenhill Baptist Church'))
            ->add(Url::create('/church')
                ->setPriority(0.9)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/homepage/may2024wide.webp'), 'About Crockenhill Baptist Church'))
            ->add(Url::create('/community')
                ->setPriority(0.9)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/homepage/may2024wide.webp'), 'Community activities at Crockenhill Baptist Church'))
            ->add(Url::create('/calendar')
                ->setPriority(0.5)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/homepage/may2024wide.webp'), 'Church Calendar'))
            ->add(Url::create('/christ/sermons')
                ->setPriority(0.8)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY)
                ->addImage(asset('/images/headings/large/sermons.webp'), 'Sermons at Crockenhill Baptist Church'))
            ->add(Url::create('/christ/sermons/preachers')
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/headings/large/sermons.webp'), 'Preachers at Crockenhill Baptist Church'))
            ->add(Url::create('/christ/sermons/series')
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage(asset('/images/headings/large/sermons.webp'), 'Sermon Series'))
            ->add(Url::create('/christ/sermons/morning')
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY)
                ->addImage(asset('/images/headings/large/sermons.webp'), 'Sunday Morning Services'))
            ->add(Url::create('/christ/sermons/evening')
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY)
                ->addImage(asset('/images/headings/large/sermons.webp'), 'Sunday Evening Services'));

        if ($this->exposurePolicy->childrensTalksArePublic()) {
            $sitemap->add(
                Url::create('/christ/childrens-corner')
                    ->setPriority(0.7)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
            );
        }

        /**
         * Performance Optimization: Use lazy() to iterate through models one by one,
         * keeping memory usage low for sites with large numbers of sermons.
         */
        $sermons = Sermon::query()
            ->select(['id', 'title', 'date', 'slug', 'updated_at', 'video_file_path', 'video_quality_status', 'video_visibility_override', 'thumbnail_file_path', 'thumbnail_generated_at', 'summary', 'show_summary', 'duration', 'preacher', 'preacher_id', 'reference', 'series', 'meta_description', 'content_type', 'scripture_passage_id'])
            ->with([
                'preacherProfile:id,name,slug',
                'scripturePassage:id,display_reference,normalized_reference',
            ])
            ->whereVisibleInSitemap()
            ->lazy();

        $now = now();

        $sitemap
            // Dynamic content via Sitemapable models
            // Eager load relationships to prevent N+1 queries during sitemap generation
            ->add($sermons->map(fn (Sermon $sermon): Url => $this->sermonSitemapPresenter->toSitemapTag($sermon, $now)))
            ->add(
                Page::query()
                    ->public()
                    ->where('area', '!=', PageArea::Members->value)
                    ->select(['id', 'slug', 'area', 'updated_at', 'description', 'heading'])
                    /**
                     * Performance Optimization: Only eager load 'media' (needed for images),
                     * and remove 'meeting' as it is not utilized in sitemap generation.
                     */
                    ->with(['media'])
                    ->lazy()
                    ->map(fn (Page $page): Url|string|array => $this->pageSitemapPresenter->toSitemapTag($page))
            )
            ->add(
                Meeting::query()
                    /**
                     * Performance Optimization: Only select columns required for sitemap generation
                     * to reduce memory usage.
                     */
                    ->select(['id', 'slug', 'updated_at', 'page_id'])
                    ->with(['media', 'page:id,heading'])
                    ->publiclyAccessible()
                    ->lazy()
                    ->map(fn (Meeting $meeting): Url|string|array => $this->meetingSitemapPresenter->toSitemapTag($meeting))
            )
            ->add(
                Preacher::active()
                    ->select(['id', 'name', 'slug', 'image_path', 'updated_at'])
                    ->lazy()
                    ->map(fn (Preacher $preacher): Url|string|array => $this->preacherSitemapPresenter->toSitemapTag($preacher))
            );

        // Add Sermon Series
        foreach ($this->sermonRepository->getSeriesForDisplay() as $series) {
            $sitemap->add(
                Url::create('/christ/sermons/series/'.Str::slug($series))
                    ->setPriority(0.6)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            );
        }

        $sitemap->writeToFile($sitemapPath);

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
