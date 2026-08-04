<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Models\ChurchService;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Sermon\SermonExposurePolicy;
use App\Sitemap\SermonSitemapPresenter;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapService
{
    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonRepository $sermonRepository,
        private readonly SermonSitemapPresenter $sermonSitemapPresenter,
        private readonly PublicChurchServiceArchiveService $churchServiceArchive,
    ) {}

    public function generate(): bool
    {
        $sitemap = Sitemap::create();

        $this->addStaticUrls($sitemap);
        $this->addChurchServices($sitemap);
        $this->addSermons($sitemap);
        $this->addPages($sitemap);
        $this->addMeetings($sitemap);
        $this->addPreachers($sitemap);
        $this->addSeries($sitemap);
        $this->addBooks($sitemap);

        $this->replaceSitemapFileAtomically($sitemap, $this->getFilePath());

        return true;
    }

    /**
     * Nginx serves the live file concurrently, so it must never be truncated
     * in place: render to a sibling temporary file, verify it is complete
     * XML, then swap it in with a same-filesystem atomic rename. On any
     * failure the previous sitemap remains untouched.
     */
    private function replaceSitemapFileAtomically(Sitemap $sitemap, string $path): void
    {
        $temporaryPath = $path.'.'.uniqid('tmp', true);

        try {
            $this->writeSitemapFile($sitemap, $temporaryPath);
            $this->assertWellFormedXml($temporaryPath);

            if (! rename($temporaryPath, $path)) {
                throw new \RuntimeException("Failed to atomically replace sitemap at {$path}");
            }
        } catch (\Throwable $exception) {
            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    /**
     * Extracted so tests can simulate a corrupt or failed render.
     */
    protected function writeSitemapFile(Sitemap $sitemap, string $temporaryPath): void
    {
        $sitemap->writeToFile($temporaryPath);
    }

    private function assertWellFormedXml(string $path): void
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_file($path);
        } finally {
            libxml_use_internal_errors($previousUseInternalErrors);
        }

        if ($document === false) {
            throw new \RuntimeException("Generated sitemap at {$path} is not well-formed XML");
        }
    }

    private function addStaticUrls(Sitemap $sitemap): void
    {
        $urls = [
            route('home'),
            route('pages.christ'),
            route('pages.christmas'),
            route('pages.church'),
            route('pages.community'),
            route('calendar.index'),
            route('church.services.index'),
            route('sermons.index'),
            route('sermons.preachers'),
            route('sermons.series'),
            route('sermons.service', 'morning'),
            route('sermons.service', 'evening'),
        ];

        if ($this->exposurePolicy->childrensTalksArePublic()) {
            $urls[] = route('childrens-corner.index');
        }

        foreach ($urls as $url) {
            $sitemap->add(Url::create($url));
        }
    }

    private function addChurchServices(Sitemap $sitemap): void
    {
        /** @var iterable<int, ChurchService> $services */
        $services = $this->churchServiceArchive->query()
            ->select(['church_services.id', 'church_services.date', 'church_services.service', 'church_services.updated_at'])
            ->lazy();

        foreach ($services as $churchService) {
            $sitemap->add($this->detailUrl(
                route('church.services.show', [
                    'date' => $churchService->date->format('Y-m-d'),
                    'service' => $churchService->service->value,
                ]),
                $churchService->updated_at,
            ));
        }
    }

    private function addSermons(Sitemap $sitemap): void
    {
        $sermons = Sermon::query()
            ->select(['id', 'title', 'date', 'slug', 'service', 'updated_at', 'audio_file_path', 'video_file_path', 'video_quality_status', 'video_visibility_override', 'thumbnail_file_path', 'thumbnail_generated_at', 'thumbnail_metadata', 'summary', 'show_summary', 'duration', 'preacher', 'preacher_id', 'reference', 'series', 'meta_description', 'content_type', 'scripture_passage_id'])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ])
            ->whereVisibleInSitemap()
            ->lazy();

        $sitemap->add($sermons->map(
            fn (Sermon $sermon): Url => $this->sermonSitemapPresenter->toSitemapTag($sermon)
        ));
    }

    private function addPages(Sitemap $sitemap): void
    {
        $pages = Page::query()
            ->public()
            ->where('area', '!=', PageArea::Members)
            ->whereColumn('slug', '!=', 'area')
            ->where(fn ($query) => $query
                ->where('area', '!=', PageArea::Sermons->value)
                ->orWhereNotIn('slug', ['preachers', 'series', 'all']))
            ->where(fn ($query) => $query
                ->where('area', '!=', PageArea::Christ->value)
                ->orWhere('slug', '!=', 'sermons'))
            ->where(fn ($query) => $query
                ->where('area', '!=', PageArea::Community->value)
                ->orWhereNotExists(fn ($meetingQuery) => $meetingQuery
                    ->selectRaw('1')
                    ->from('meetings')
                    ->whereColumn('meetings.slug', 'pages.slug')))
            ->select(['id', 'slug', 'area', 'updated_at'])
            ->lazy();

        foreach ($pages as $page) {
            if (! is_string($page->route) || $page->route === '') {
                continue;
            }

            $sitemap->add($this->detailUrl($page->route, $page->updated_at));
        }
    }

    private function addMeetings(Sitemap $sitemap): void
    {
        $meetings = Meeting::query()
            ->select(['id', 'slug', 'updated_at'])
            ->publiclyAccessible()
            ->lazy();

        foreach ($meetings as $meeting) {
            $sitemap->add($this->detailUrl(
                route('meetings.show', ['meeting' => $meeting->slug]),
                $meeting->updated_at,
            ));
        }
    }

    private function addPreachers(Sitemap $sitemap): void
    {
        $preachers = Preacher::query()
            ->active()
            ->select(['id', 'slug', 'updated_at'])
            ->lazy();

        foreach ($preachers as $preacher) {
            $sitemap->add($this->detailUrl(
                route('sermons.preacher', ['preacher' => $preacher->slug]),
                $preacher->updated_at,
            ));
        }
    }

    private function addSeries(Sitemap $sitemap): void
    {
        // Uncached on purpose: a flexible-cache read in its stale window
        // returns the old list and defers the refresh until after the file
        // is written, delaying a new archive URL by a full extra day.
        foreach ($this->sermonRepository->getExistingSeries() as $series) {
            $sitemap->add(Url::create(
                route('sermons.series.show', ['series' => Str::slug($series)])
            ));
        }
    }

    private function addBooks(Sitemap $sitemap): void
    {
        foreach ($this->sermonRepository->getExistingScriptureBooks() as $book) {
            $sitemap->add(Url::create(route('sermons.index', ['book' => $book])));
        }
    }

    private function detailUrl(string $location, ?CarbonInterface $updatedAt): Url
    {
        $url = Url::create($location);

        if ($updatedAt && $updatedAt->year > 0) {
            $url->setLastModificationDate($updatedAt);
        }

        return $url;
    }

    public function getFilePath(): string
    {
        // Parallel test workers each write their own file; a shared path would race.
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
