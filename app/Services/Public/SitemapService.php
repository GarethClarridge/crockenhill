<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Sermon\SermonExposurePolicy;
use App\Sitemap\MeetingSitemapPresenter;
use App\Sitemap\PageSitemapPresenter;
use App\Sitemap\PreacherSitemapPresenter;
use App\Sitemap\SermonSitemapPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Generate sitemap.xml
     */
    public function generate(): bool
    {
        $sitemapPath = $this->getFilePath();
        $now = now();

        $sitemap = Sitemap::create();

        $this->addStaticUrls($sitemap);
        $this->addSermons($sitemap, $now);
        $this->addPages($sitemap);
        $this->addMeetings($sitemap);
        $this->addPreachers($sitemap);
        $this->addSeries($sitemap);
        $this->addBooks($sitemap);

        $sitemap->writeToFile($sitemapPath);

        return true;
    }

    /**
     * Add static high-priority URLs to the sitemap.
     *
     * Performance Optimization: Resolves asset URLs once before adding multiple URLs
     * to avoid redundant asset() helper calls and container lookups.
     */
    private function addStaticUrls(Sitemap $sitemap): void
    {
        $homepageImage = asset('/images/homepage/may2024wide.webp');
        $sermonsImage = asset('/images/headings/large/sermons.webp');

        $representatives = $this->getRepresentativeSermonsForStaticUrls();

        $sitemap
            ->add(Url::create(route('home'))
                ->setPriority(1.0)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($homepageImage, 'Crockenhill Baptist Church'))
            ->add(Url::create(route('pages.christ'))
                ->setPriority(0.9)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($homepageImage, 'Learn about Jesus Christ at Crockenhill Baptist Church'))
            ->add(Url::create(route('pages.christmas'))
                ->setPriority(0.8)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                ->addImage(asset('/images/homepage/christmas2023.webp'), 'Christmas at Crockenhill Baptist Church'))
            ->add(Url::create(route('pages.church'))
                ->setPriority(0.9)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($homepageImage, 'About Crockenhill Baptist Church'))
            ->add(Url::create(route('pages.community'))
                ->setPriority(0.9)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($homepageImage, 'Community activities at Crockenhill Baptist Church'))
            ->add(Url::create(route('calendar.index'))
                ->setPriority(0.5)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($homepageImage, 'Church Calendar'));

        $sermonsUrl = Url::create(route('sermons.index'))
            ->setPriority(0.8)
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY);

        $latestAll = $representatives->get('all');
        if ($latestAll?->updated_at && $latestAll->updated_at->year > 0) {
            $sermonsUrl->setLastModificationDate($latestAll->updated_at);
        }
        $sermonsUrl->addImage(
            ($latestAll ? $this->sermonViewPresenter->thumbnailUrl($latestAll) : null) ?: $sermonsImage,
            'Sermons at Crockenhill Baptist Church'
        );
        $sitemap->add($sermonsUrl);

        $sitemap
            ->add(Url::create(route('sermons.preachers'))
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($sermonsImage, 'Preachers at Crockenhill Baptist Church'))
            ->add(Url::create(route('sermons.series'))
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->addImage($sermonsImage, 'Sermon Series'));

        $morningUrl = Url::create(route('sermons.service', 'morning'))
            ->setPriority(0.7)
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY);
        $latestMorning = $representatives->get('morning');
        if ($latestMorning?->updated_at && $latestMorning->updated_at->year > 0) {
            $morningUrl->setLastModificationDate($latestMorning->updated_at);
        }
        $morningUrl->addImage(
            ($latestMorning ? $this->sermonViewPresenter->thumbnailUrl($latestMorning) : null) ?: $sermonsImage,
            'Sunday Morning Services'
        );
        $sitemap->add($morningUrl);

        $eveningUrl = Url::create(route('sermons.service', 'evening'))
            ->setPriority(0.7)
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY);
        $latestEvening = $representatives->get('evening');
        if ($latestEvening?->updated_at && $latestEvening->updated_at->year > 0) {
            $eveningUrl->setLastModificationDate($latestEvening->updated_at);
        }
        $eveningUrl->addImage(
            ($latestEvening ? $this->sermonViewPresenter->thumbnailUrl($latestEvening) : null) ?: $sermonsImage,
            'Sunday Evening Services'
        );
        $sitemap->add($eveningUrl);

        if ($this->exposurePolicy->childrensTalksArePublic()) {
            $childrensUrl = Url::create(route('childrens-corner.index'))
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY);
            $latestChildrens = $representatives->get('childrens-talk');
            if ($latestChildrens?->updated_at && $latestChildrens->updated_at->year > 0) {
                $childrensUrl->setLastModificationDate($latestChildrens->updated_at);
            }
            $childrensUrl->addImage(
                ($latestChildrens ? $this->sermonViewPresenter->thumbnailUrl($latestChildrens) : null) ?: $sermonsImage,
                "Children's Corner"
            );
            $sitemap->add($childrensUrl);
        }
    }

    /**
     * Fetch the latest representative sermons for the main archive landing pages.
     *
     * Performance Optimization: Uses a window function to retrieve the most recent
     * sermon for multiple logical groupings in a single database round-trip,
     * ensuring sitemap freshness without incurring N+1 overhead.
     *
     * @return Collection<string, Sermon>
     */
    private function getRepresentativeSermonsForStaticUrls(): Collection
    {
        $morning = SermonService::Morning->value;
        $evening = SermonService::Evening->value;
        $sermon = SermonContentType::Sermon->value;
        $talk = SermonContentType::ChildrensTalk->value;

        $subquery = Sermon::query()
            ->whereVisibleInSitemap()
            ->select([
                'id',
                'title',
                'date',
                'slug',
                'service',
                'content_type',
                'thumbnail_file_path',
                'thumbnail_generated_at',
                'thumbnail_metadata',
                'video_file_path',
                'video_visibility_override',
                'video_quality_status',
                'updated_at',
                'preacher',
                'preacher_id',
            ])
            ->selectRaw("
                CASE
                    WHEN content_type = '{$sermon}' THEN 'all'
                    WHEN content_type = '{$talk}' THEN 'childrens-talk'
                    ELSE 'other'
                END as type_group,
                CASE
                    WHEN content_type = '{$sermon}' AND service = '{$morning}' THEN 'morning'
                    WHEN content_type = '{$sermon}' AND service = '{$evening}' THEN 'evening'
                    ELSE 'none'
                END as service_group
            ")
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY content_type ORDER BY date DESC, id DESC) as type_rank')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY content_type, service ORDER BY date DESC, id DESC) as service_rank');

        /**
         * Performance Optimization: Only eager load preacherProfile to avoid N+1
         * when resolving thumbnails for the representative sermons.
         */
        $sermons = Sermon::query()
            ->fromSub($subquery, 'ranked')
            ->with(['preacherProfile:id,name,slug,image_path'])
            ->where(fn ($q) => $q->where('type_rank', 1)->orWhere('service_rank', 1))
            ->get();

        $results = collect();

        // The 'all' group is the latest 'Sermon' regardless of service.
        $latestSermon = $sermons->first(fn (Sermon $s) => $s->getAttribute('type_group') === 'all' && (int) $s->getAttribute('type_rank') === 1);
        if ($latestSermon) {
            $results->put('all', $latestSermon);
        }

        // Morning and Evening latest sermons.
        $latestMorning = $sermons->first(fn (Sermon $s) => $s->getAttribute('service_group') === 'morning' && (int) $s->getAttribute('service_rank') === 1);
        if ($latestMorning) {
            $results->put('morning', $latestMorning);
        }

        $latestEvening = $sermons->first(fn (Sermon $s) => $s->getAttribute('service_group') === 'evening' && (int) $s->getAttribute('service_rank') === 1);
        if ($latestEvening) {
            $results->put('evening', $latestEvening);
        }

        // Children's Talk latest.
        $latestTalk = $sermons->first(fn (Sermon $s) => $s->getAttribute('type_group') === 'childrens-talk' && (int) $s->getAttribute('type_rank') === 1);
        if ($latestTalk) {
            $results->put('childrens-talk', $latestTalk);
        }

        return $results;
    }

    /**
     * Add dynamic sermon URLs to the sitemap.
     */
    private function addSermons(Sitemap $sitemap, Carbon $now): void
    {
        /**
         * Performance Optimization: Use lazy() to iterate through models one by one,
         * keeping memory usage low for sites with large numbers of sermons.
         */
        $sermons = Sermon::query()
            ->select(['id', 'title', 'date', 'slug', 'service', 'updated_at', 'audio_file_path', 'video_file_path', 'video_quality_status', 'video_visibility_override', 'thumbnail_file_path', 'thumbnail_generated_at', 'thumbnail_metadata', 'summary', 'show_summary', 'duration', 'preacher', 'preacher_id', 'reference', 'series', 'meta_description', 'content_type', 'scripture_passage_id'])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ])
            ->whereVisibleInSitemap()
            ->lazy();

        $sitemap->add($sermons->map(fn (Sermon $sermon): Url => $this->sermonSitemapPresenter->toSitemapTag($sermon, $now)));
    }

    /**
     * Add Bible book filtered sermon archive URLs to the sitemap.
     *
     * Performance Optimization: Fetches latest representative sermons for all books
     * in a single bulk query using a window function to eliminate N+1 bottlenecks
     * during sitemap generation.
     */
    private function addBooks(Sitemap $sitemap): void
    {
        $books = $this->sermonRepository->getScriptureBooks();
        $sermonsImage = asset('/images/headings/large/sermons.webp');

        if ($books->isEmpty()) {
            return;
        }

        $subquery = Sermon::query()
            ->join('sermon_scripture_filters', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
            ->whereSermon()
            ->select([
                'sermons.id',
                'sermons.title',
                'sermons.date',
                'sermons.slug',
                'sermons.service',
                'sermons.thumbnail_file_path',
                'sermons.thumbnail_generated_at',
                'sermons.thumbnail_metadata',
                'sermons.video_file_path',
                'sermons.video_visibility_override',
                'sermons.video_quality_status',
                'sermons.content_type',
                'sermons.updated_at',
                'sermons.preacher',
                'sermons.preacher_id',
                'sermon_scripture_filters.bible_book as book_group',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sermon_scripture_filters.bible_book ORDER BY sermons.date DESC, sermons.id DESC) as row_num');

        /**
         * Performance Optimization: Eager load preacher profiles to avoid N+1 queries
         * when resolving thumbnail and metadata for the representative sermons.
         */
        $representativeSermons = Sermon::query()
            ->fromSub($subquery, 'ranked')
            ->with(['preacherProfile:id,name,slug,image_path'])
            ->where('row_num', 1)
            ->get()
            ->keyBy('book_group');

        foreach ($books as $book) {
            $url = Url::create(route('sermons.index', ['book' => $book]))
                ->setPriority(0.7)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY);

            $latestSermon = $representativeSermons->get($book);

            if ($latestSermon && $latestSermon->updated_at && $latestSermon->updated_at->year > 0) {
                $url->setLastModificationDate($latestSermon->updated_at);
            }

            $image = $latestSermon ? $this->sermonViewPresenter->thumbnailUrl($latestSermon) : null;

            $url->addImage($image ?: $sermonsImage, "Sermons on {$book}");

            $sitemap->add($url);
        }
    }

    /**
     * Add dynamic page URLs to the sitemap.
     */
    private function addPages(Sitemap $sitemap): void
    {
        $pages = Page::query()
            ->public()
            ->where('area', '!=', PageArea::Members)
            ->whereColumn('slug', '!=', 'area')
            ->where(function ($query): void {
                // Exclude sermon-area index pages whose slugs duplicate static routes.
                // The (area, slug) unique constraint means the same slug in another area is a distinct URL.
                $query->where('area', '!=', PageArea::Sermons->value)
                    ->orWhereNotIn('slug', ['preachers', 'series', 'all']);
            })
            ->where(function ($query): void {
                // Exclude the christ-area "sermons" page: /christ/sermons is the canonical
                // sermons index already emitted by addStaticUrls() via route('sermons.index').
                // Unlike the Sermons-area indexes above, this duplicate lives in the christ area.
                $query->where('area', '!=', PageArea::Christ->value)
                    ->orWhere('slug', '!=', 'sermons');
            })
            ->where(function ($query): void {
                // Only exclude community pages whose slug matches a meeting slug,
                // since meeting URLs are always /community/{meeting-slug} and would
                // otherwise emit a duplicate <loc> for the same URL. The match is on
                // slug — not the meeting() FK — because production meetings are not
                // reliably linked back to their page via page_id. Pages in other
                // areas share no URL space with meetings and must stay.
                $query->where('area', '!=', PageArea::Community->value)
                    ->orWhereNotExists(function ($meetingQuery): void {
                        $meetingQuery->select(DB::raw(1))
                            ->from('meetings')
                            ->whereColumn('meetings.slug', 'pages.slug');
                    });
            })
            ->select(['id', 'slug', 'area', 'updated_at', 'description', 'heading'])
            /**
             * Performance Optimization: Only eager load 'media' (needed for images),
             * and remove 'meeting' as it is not utilized in sitemap generation.
             */
            ->with(['media'])
            ->lazy();

        $sitemap->add($pages->map(fn (Page $page): Url|string|array => $this->pageSitemapPresenter->toSitemapTag($page)));
    }

    /**
     * Add dynamic meeting URLs to the sitemap.
     */
    private function addMeetings(Sitemap $sitemap): void
    {
        $meetings = Meeting::query()
            /**
             * Performance Optimization: Only select columns required for sitemap generation
             * to reduce memory usage.
             */
            ->select(['id', 'slug', 'updated_at', 'page_id'])
            ->with(['media', 'page:id,heading'])
            ->publiclyAccessible()
            ->lazy();

        $sitemap->add($meetings->map(fn (Meeting $meeting): Url|string|array => $this->meetingSitemapPresenter->toSitemapTag($meeting)));
    }

    /**
     * Add dynamic preacher URLs to the sitemap.
     */
    private function addPreachers(Sitemap $sitemap): void
    {
        $preachers = Preacher::query()->active()
            ->select(['id', 'name', 'slug', 'image_path', 'updated_at'])
            ->with([
                'latestSermon' => fn ($query) => $query
                    ->select([
                        'sermons.id',
                        'sermons.preacher_id',
                        'sermons.title',
                        'sermons.date',
                        'sermons.slug',
                        'sermons.service',
                        'sermons.audio_file_path',
                        'sermons.thumbnail_file_path',
                        'sermons.thumbnail_generated_at',
                        'sermons.thumbnail_metadata',
                        'sermons.video_file_path',
                        'sermons.video_visibility_override',
                        'sermons.video_quality_status',
                        'sermons.content_type',
                        'sermons.updated_at',
                    ])
                    ->with(['preacherProfile:id,name,slug,image_path']),
            ])
            ->lazy();

        $sitemap->add($preachers->map(fn (Preacher $preacher): Url|string|array => $this->preacherSitemapPresenter->toSitemapTag($preacher)));
    }

    /**
     * Add sermon series URLs to the sitemap.
     */
    private function addSeries(Sitemap $sitemap): void
    {
        $seriesList = $this->sermonRepository->getSeriesForDisplay();
        $sermonsImage = asset('/images/headings/large/sermons.webp');

        if ($seriesList === []) {
            return;
        }

        $subquery = Sermon::query()
            ->whereSermon()
            ->whereNotNull('series')
            ->where('series', '!=', '')
            ->select([
                'id',
                'title',
                'date',
                'slug',
                'service',
                'series',
                'thumbnail_file_path',
                'thumbnail_generated_at',
                'thumbnail_metadata',
                'video_file_path',
                'video_visibility_override',
                'video_quality_status',
                'content_type',
                'updated_at',
                'preacher',
                'preacher_id',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY series ORDER BY date DESC, id DESC) as row_num');

        /**
         * Performance Optimization: Eager load preacher profiles to avoid N+1 queries
         * when resolving thumbnail and metadata for the representative sermons.
         */
        $representativeSermons = Sermon::query()
            ->fromSub($subquery, 'ranked')
            ->with(['preacherProfile:id,name,slug,image_path'])
            ->where('row_num', 1)
            ->get()
            ->keyBy('series');

        foreach ($seriesList as $series) {
            $url = Url::create(route('sermons.series.show', ['series' => Str::slug($series)]))
                ->setPriority(0.6)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY);

            $latestSermon = $representativeSermons->get($series);

            if ($latestSermon && $latestSermon->updated_at && $latestSermon->updated_at->year > 0) {
                $url->setLastModificationDate($latestSermon->updated_at);
            }

            $image = $latestSermon ? $this->sermonViewPresenter->thumbnailUrl($latestSermon) : null;

            $url->addImage($image ?: $sermonsImage, "Sermon Series: {$series}");

            $sitemap->add($url);
        }
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
