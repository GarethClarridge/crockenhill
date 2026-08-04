<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\RelatedPagePresenter;
use App\Presenters\SermonViewPresenter;
use App\Seo\PreacherItemListPresenter;
use App\Seo\SeriesItemListPresenter;
use App\Seo\SermonArchiveSeoPresenter;
use App\Seo\SermonItemListPresenter;
use App\Services\Public\PreacherListCache;
use App\Services\Public\PublicChurchServiceArchiveService;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonPageContextService;
use App\Support\BibleCanon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SermonController extends Controller
{
    public function __construct(
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly SermonRepository $sermonRepository,
        private readonly SermonItemListPresenter $itemListPresenter,
        private readonly SermonViewPresenter $sermonViewPresenter,
        private readonly SermonArchiveSeoPresenter $seoPresenter,
        private readonly PublicChurchServiceArchiveService $churchServiceArchive,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * The BrowseSermons Livewire component owns the paginated sermon query and filter
     * normalization. The controller renders the page shell and handles SEO metadata.
     */
    public function index(Request $request, BibleCanon $bibleCanon): View
    {
        $filters = $bibleCanon->normalizeArchiveFilters(
            $request->query('book'),
            $request->query('chapter'),
            $request->query('preacher'),
            $request->query('series'),
        );

        $page = $request->integer('page', 1);

        return view('sermons.index', [
            'heading' => $this->seoPresenter->title($filters, $page),
            'description' => $this->seoPresenter->description($filters, $page),
            'canonical_url' => $this->seoPresenter->canonical($filters, $page),
            'area' => 'christ',
            'links' => $this->sermonLinks('sermons'),
            'slug' => 'sermons',
        ]);
    }

    public function all(Request $request): RedirectResponse
    {
        return redirect()->to(route('sermons.index', $request->query()), 301);
    }

    /**
     * Display the specified resource.
     *
     * The slug-only route is a legacy convenience URL. Regular sermons redirect
     * 301 to the canonical date-based URL so all inbound links, HTML canonical
     * tags, sitemap entries, and feed enclosures agree on one URL shape.
     * Children's talks redirect to their dedicated URL only when public.
     */
    public function show(
        Sermon $sermon,
        SermonPageContextService $pageContextService,
        SermonExposurePolicy $exposurePolicy
    ): View|RedirectResponse {
        // Children's talks: redirect to childrens-corner when public.
        if ($exposurePolicy->shouldRedirectGenericSermonRoute($sermon)) {
            return redirect()->to($exposurePolicy->canonicalUrl($sermon), 301);
        }

        // Regular sermons: always redirect slug-only route to canonical date-based URL.
        if (! $exposurePolicy->isChildrensTalk($sermon)) {
            return redirect()->to($exposurePolicy->canonicalUrl($sermon), 301);
        }

        // Non-public children's talks are not accessible via the public sermon route.
        abort(404);
    }

    /**
     * Render sermon view from the canonical date-based route.
     */
    private function renderSermon(Sermon $sermon, SermonPageContextService $pageContextService): View
    {
        abort_unless($sermon->content_type === SermonContentType::Sermon, 404);

        /**
         * Performance Optimization: Limits retrieved columns for related models to
         * required fields to reduce memory usage and DB I/O on the single sermon view.
         */
        $sermon->loadMissing([
            'scripturePassage:id,display_reference,normalized_reference,html_content,copyright,fums_token',
            'preacherProfile:id,name,slug,image_path',
            'publishedServiceSection:id,published_sermon_id,media_processing_log_id',
            // Table-prefixed columns are required here: latestOfMany() uses an inner self-join,
            // so ambiguous column names (id, sermon_id, created_at) must be qualified.
            'latestProcessingLog' => fn ($query) => $query
                ->select(['media_processing_logs.id', 'processing_id', 'media_processing_logs.sermon_id', 'status', 'current_step', 'error_message', 'original_filename', 'media_processing_logs.created_at', 'duration']),
            'livestreamProcessing' => fn ($query) => $query
                ->select(['id', 'processing_id', 'sermon_id', 'original_filename', 'created_at', 'status', 'duration'])
                ->withCount('segments'),
        ]);

        $heading = $sermon->title;
        $pageContext = $pageContextService->build($sermon);

        $sermonView = $this->sermonViewPresenter->present($sermon);
        $fullTitle = $sermon->title.' | '.($sermonView['preacher_name'] ?? 'Unknown preacher');

        return view('sermons.sermon', [
            'slug' => $sermon->slug,
            'heading' => $heading,
            'content' => '',
            'sermon' => $sermon,
            'sermonView' => $sermonView,
            'fullTitle' => $fullTitle,
            'metaDescription' => $this->sermonViewPresenter->metaDescription($sermon),
            'readingReference' => $pageContext['reading_reference'],
            'readingUrl' => $pageContext['reading_url'],
            'serviceHistoryUrl' => $this->churchServiceArchive->publicUrlForSermon($sermon),
            'area' => 'christ',
            'links' => $this->sermonLinks($sermon->slug, ['homepage']),
        ]);
    }

    public function preachers(PreacherItemListPresenter $itemListPresenter, PreacherListCache $preacherListRepository): View
    {
        $page = Page::query()
            ->select(['id', 'slug', 'body'])
            ->where('slug', 'preachers')
            ->first();

        /**
         * Performance Optimization: Use cached preacher list with counts to reduce
         * DB I/O and complex subqueries on every request.
         */
        $preachers = $preacherListRepository->forPublicList();

        return view('sermons.preachers', [
            'preachers' => $preachers,
            'json_ld_data' => $itemListPresenter->toItemList($preachers),
            'heading' => 'Preachers',
            'description' => 'Preachers at Crockenhill Baptist Church.',
            'content' => $page ? $page->body : '',
            'area' => 'christ',
            'links' => $this->sermonLinks('preachers'),
            'slug' => 'preachers',
        ]);
    }

    /**
     * Display sermons for a specific preacher.
     *
     * Performance Optimization: Uses the cached sermon listing from the repository
     * to reduce redundant DB queries when viewing preacher profiles. Bulk presents
     * the collection once for both view and JSON-LD to minimize processing overhead.
     */
    public function preacher(Preacher $preacher): View
    {
        /**
         * Performance Optimization: Use Repository to fetch cached preacher sermon listing.
         */
        $sermons = $this->sermonRepository->getSermonsByPreacher($preacher);
        $presented = $this->sermonViewPresenter->presentCollection($sermons);

        $shareImage = $preacher->profile_image_url;
        if ($shareImage === null && $sermons->isNotEmpty()) {
            $shareImage = $this->sermonViewPresenter->thumbnailUrl($sermons->first());
        }

        return view('sermons.preacher', [
            'preacher' => $preacher,
            'sermons' => $sermons,
            'presentedSermons' => $presented,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => $this->seoPresenter->preacherTitle($preacher),
            'description' => $this->seoPresenter->preacherDescription($preacher),
            'area' => 'christ',
            'links' => $this->sermonLinks('preachers'),
            'slug' => 'preachers',
            'share_image' => $shareImage,
        ]);
    }

    public function series(SeriesItemListPresenter $itemListPresenter): View
    {
        $series = collect($this->sermonRepository->getSeriesForDisplay());

        $seriesUrls = $series->mapWithKeys(fn (string $name): array => [
            $name => route('sermons.series.show', ['series' => Str::slug($name)]),
        ]);

        return view('sermons.serieses', [
            'series' => $series,
            'seriesUrls' => $seriesUrls,
            'json_ld_data' => $itemListPresenter->toItemList($series),
            'heading' => 'Sermon Series',
            'description' => 'Browse sermon series from Crockenhill Baptist Church.',
            'area' => 'christ',
            'links' => $this->sermonLinks('series'),
            'slug' => 'series',
        ]);
    }

    public function seriesShow(string $series): View
    {
        $series_name = $this->sermonRepository->resolveSeriesNameFromSlug($series);

        if ($series_name === null) {
            abort(404, 'Sermon series not found.');
        }

        /**
         * Performance Optimization: Use Repository to fetch cached series listing.
         */
        $sermons = $this->sermonRepository->getSermonsBySeries($series_name);
        $presented = $this->sermonViewPresenter->presentCollection($sermons);

        $shareImage = null;
        if ($sermons->isNotEmpty()) {
            $shareImage = $this->sermonViewPresenter->thumbnailUrl($sermons->first());
        }

        return view('sermons.series', [
            'sermons' => $sermons,
            'presentedSermons' => $presented,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => $this->seoPresenter->seriesTitle($series_name),
            'description' => $this->seoPresenter->seriesDescription($series_name),
            'area' => 'christ',
            'links' => $this->sermonLinks('series'),
            'slug' => 'series',
            'share_image' => $shareImage,
        ]);
    }

    public function service(string $service): View
    {
        $serviceEnum = SermonService::from($service);

        /**
         * Performance Optimization: Use Repository to fetch cached service listing.
         */
        $sermons = $this->sermonRepository->getSermonsByService($serviceEnum);
        $presented = $this->sermonViewPresenter->presentCollection($sermons);

        return view('sermons.service', [
            'sermons' => $sermons,
            'presentedSermons' => $presented,
            'service' => $service,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => $this->seoPresenter->serviceTitle($serviceEnum, $service),
            'description' => $this->seoPresenter->serviceDescription($serviceEnum, $service),
            'area' => 'christ',
            'links' => $this->sermonLinks($service),
            'slug' => $service,
        ]);
    }

    public function showDated(
        int $year,
        int $month,
        Sermon $sermon,
        SermonPageContextService $pageContextService,
        SermonExposurePolicy $exposurePolicy
    ): View|RedirectResponse {
        // Children's talks have a dedicated URL shape — redirect when they are public.
        if ($exposurePolicy->shouldRedirectGenericSermonRoute($sermon)) {
            return redirect()->to($exposurePolicy->canonicalUrl($sermon), 301);
        }

        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->renderSermon($sermon, $pageContextService);
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    private function sermonLinks(string $slugToExclude, array $extraExcludedSlugs = []): Collection
    {
        return $this->relatedPagePresenter->ordered(
            linkArea: 'sermons',
            slugToExclude: $slugToExclude,
            secondSlugToExclude: 'christ',
            excludeAdminPages: true,
            extraExcludedSlugs: $extraExcludedSlugs,
        );
    }
}
