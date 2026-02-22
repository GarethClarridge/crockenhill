<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapService
{
    public function generate(): bool
    {
        $sitemapPath = $this->getFilePath();

        Sitemap::create()
            // Static high-priority URLs
            ->add(Url::create('/')->setPriority(1.0)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/christ')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/church')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/community')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/calendar')->setPriority(0.5)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/christ/sermons')->setPriority(0.8)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY))

            // Dynamic content via Sitemapable models
            // Eager load relationships to prevent N+1 queries during sitemap generation
            // Performance Optimization: Use select() to limit columns and reduce memory usage for large collections
            ->add(Sermon::with('preacherProfile:id,name')->select([
                'id',
                'date',
                'slug',
                'updated_at',
                'video_file_path',
                'thumbnail_file_path',
                'title',
                'summary',
                'duration',
                'meta_description',
                'preacher',
                'preacher_id',
                'reference',
                'series',
            ])->get())
            ->add(Page::with('media')->select(['id', 'slug', 'area', 'updated_at', 'description', 'heading'])->where('admin', 'no')->get())
            ->add(Meeting::select(['id', 'slug', 'updated_at'])->get())

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
