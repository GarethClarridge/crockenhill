<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\PreacherItemListPresenter;
use App\Presenters\RelatedPagePresenter;
use App\Presenters\SeriesItemListPresenter;
use App\Presenters\SermonItemListPresenter;
use App\Presenters\SermonViewPresenter;
use App\Repositories\SermonRepository;
use App\Services\SermonExposurePolicy;
use App\Services\SermonPageContextService;
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
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $filters = $this->archiveFilters(request());
        $sermons = $this->sermonRepository->publicBrowseQuery(
            book: $filters['book'],
            chapter: $filters['chapter'],
            preacherId: $filters['preacher'],
            series: $filters['series'],
        )
            ->paginate(24);

        return view('sermons.index', [
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons->getCollection()),
            'heading' => 'Sermons',
            'description' => 'Browse sermons from Crockenhill Baptist Church and filter by scripture, preacher, or series.',
            'canonical_url' => $this->archiveCanonicalUrl(request(), $filters),
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

        $sermon->loadMissing([
            'scripturePassage',
            'preacherProfile',
            'publishedServiceSection',
            'latestProcessingLog',
            'livestreamProcessing',
        ]);

        $heading = $sermon->title;
        $pageContext = $pageContextService->build($sermon);

        $sermonView = $this->sermonViewPresenter->present($sermon);
        $fullTitle = $sermon->title.' | '.($sermonView['preacher_name'] ?? 'Unknown preacher');

        return view('sermons.sermon', [
            'slug' => $sermon->slug,
            'heading' => $heading,
            'description' => $sermon->meta_description,
            'content' => '',
            'sermon' => $sermon,
            'sermonView' => $sermonView,
            'fullTitle' => $fullTitle,
            'metaDescription' => $this->sermonViewPresenter->metaDescription($sermon),
            'readingReference' => $pageContext['reading_reference'],
            'readingUrl' => $pageContext['reading_url'],
            'area' => 'christ',
            'links' => $this->sermonLinks($sermon->slug, ['homepage']),
        ]);
    }

    /**
     * @return array{book:?string, chapter:?int, preacher:?int, series:?string}
     */
    private function archiveFilters(Request $request): array
    {
        $book = $request->filled('book') ? trim((string) $request->string('book')) : null;
        $series = $request->filled('series') ? trim((string) $request->string('series')) : null;

        return [
            'book' => $book !== '' ? $book : null,
            'chapter' => $request->filled('chapter') ? $request->integer('chapter') : null,
            'preacher' => $request->filled('preacher') ? $request->integer('preacher') : null,
            'series' => $series !== '' ? $series : null,
        ];
    }

    /**
     * @param  array{book:?string, chapter:?int, preacher:?int, series:?string}  $filters
     */
    private function archiveCanonicalUrl(Request $request, array $filters): string
    {
        if (collect($filters)->filter()->isNotEmpty()) {
            return route('sermons.index');
        }

        $page = $request->integer('page', 1);

        if ($page > 1) {
            return route('sermons.index', ['page' => $page]);
        }

        return route('sermons.index');
    }

    public function preachers(PreacherItemListPresenter $itemListPresenter): View
    {
        $page = Page::query()
            ->select(['id', 'slug', 'body'])
            ->where('slug', 'preachers')
            ->first();

        /**
         * Performance Optimization: Use cached preacher list with counts to reduce
         * DB I/O and complex subqueries on every request.
         */
        $preachers = Preacher::getForPublicList();

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
     * to reduce redundant DB queries when viewing preacher profiles.
     */
    public function preacher(Preacher $preacher): View
    {
        /**
         * Performance Optimization: Use Repository to fetch cached preacher sermon listing.
         */
        $sermons = $this->sermonRepository->getSermonsByPreacher($preacher);

        return view('sermons.preacher', [
            'preacher' => $preacher,
            'sermons' => $sermons,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => 'Sermons by '.$preacher->name,
            'description' => 'Browse all sermons preached by '.$preacher->name.' at Crockenhill Baptist Church.',
            'area' => 'christ',
            'links' => $this->sermonLinks('preachers'),
            'slug' => 'preachers',
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
        $series_name = str_replace('-', ' ', Str::title($series));

        /**
         * Performance Optimization: Use Repository to fetch cached series listing.
         */
        $sermons = $this->sermonRepository->getSermonsBySeries($series_name);

        return view('sermons.series', [
            'sermons' => $sermons,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => 'Sermon Series: '.$series_name,
            'description' => 'Browse all sermons in the "'.$series_name.'" series from Crockenhill Baptist Church.',
            'area' => 'christ',
            'links' => $this->sermonLinks('series'),
            'slug' => 'series',
        ]);
    }

    public function service(string $service): View
    {
        $serviceEnum = SermonService::from($service);

        /**
         * Performance Optimization: Use Repository to fetch cached service listing.
         */
        $sermons = $this->sermonRepository->getSermonsByService($serviceEnum);

        $serviceLabel = match ($serviceEnum) {
            SermonService::Morning => 'Sunday Morning',
            SermonService::Evening => 'Sunday Evening',
            SermonService::Other => Str::title($service),
        };

        return view('sermons.service', [
            'sermons' => $sermons,
            'service' => $service,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => $serviceLabel.' Services',
            'description' => "Listen to recent {$serviceLabel} sermons from Crockenhill Baptist Church.",
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
     * @return Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>
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
