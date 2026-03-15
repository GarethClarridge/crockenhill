<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\PreacherItemListPresenter;
use App\Presenters\SermonItemListPresenter;
use App\Repositories\SermonRepository;
use App\Services\SermonExposurePolicy;
use App\Services\SermonPageContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SermonController extends Controller
{
    public function __construct(
        private readonly SermonRepository $sermonRepository,
        private readonly SermonItemListPresenter $itemListPresenter,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        /**
         * Performance Optimization: Use Repository to fetch cached sermon listing.
         */
        $latest_sermons = $this->sermonRepository->getLatestSermons();

        return view('sermons.index', [
            'latest_sermons' => $latest_sermons,
            'json_ld_data' => $this->itemListPresenter->toItemList($latest_sermons),
            'heading' => 'Sermons',
            'description' => 'Listen to recent sermons from Crockenhill Baptist Church. Worshipping God, strengthening believers, and proclaiming Jesus Christ.',
        ]);
    }

    public function getAll(): View
    {
        /**
         * Performance Optimization: Use Repository to fetch cached full sermon listing.
         */
        $sermons = $this->sermonRepository->getAllSermons();

        return view('sermons.all', [
            'sermons' => $sermons,
            'json_ld_data' => $this->itemListPresenter->toItemList($sermons),
            'heading' => 'All Sermons',
            'description' => 'Browse all sermons from Crockenhill Baptist Church. Search by date, preacher or series.',
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(
        Sermon $sermon,
        SermonPageContextService $pageContextService,
        SermonExposurePolicy $exposurePolicy
    ): View|RedirectResponse {
        if ($exposurePolicy->shouldRedirectGenericSermonRoute($sermon)) {
            return redirect()->to($exposurePolicy->publicUrl($sermon), 301);
        }

        abort_unless($sermon->content_type === SermonContentType::Sermon, 404);

        $heading = $sermon->title;
        $pageContext = $pageContextService->build($sermon);

        $sermon->loadMissing('scripturePassage', 'preacherProfile');

        return view('sermons.sermon', [
            'slug' => $sermon->slug,
            'heading' => $heading,
            'description' => $sermon->meta_description,
            'content' => '',
            'sermon' => $sermon,
            'readingReference' => $pageContext['reading_reference'],
            'readingUrl' => $pageContext['reading_url'],
        ]);
    }

    public function getPreachers(PreacherItemListPresenter $itemListPresenter): View
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
        ]);
    }

    /**
     * Display sermons for a specific preacher.
     *
     * Performance Optimization: Uses the cached sermon listing from the repository
     * to reduce redundant DB queries when viewing preacher profiles.
     */
    public function getPreacher(Preacher $preacher): View
    {
        /**
         * Performance Optimization: Use Repository to fetch cached preacher sermon listing.
         */
        $sermons = $this->sermonRepository->getSermonsByPreacher($preacher);

        return view('sermons.preacher', [
            'preacher' => $preacher,
            'sermons' => $sermons,
            'heading' => 'Sermons by '.$preacher->name,
            'description' => 'Browse all sermons preached by '.$preacher->name.' at Crockenhill Baptist Church.',
        ]);
    }

    public function getSerieses(): View
    {
        $series = collect($this->sermonRepository->getSeriesForDisplay());

        return view('sermons.serieses', [
            'series' => $series,
            'heading' => 'Sermon Series',
            'description' => 'Browse sermon series from Crockenhill Baptist Church.',
        ]);
    }

    public function getSeries(string $series): View
    {
        $series_name = str_replace('-', ' ', Str::title($series));

        /**
         * Performance Optimization: Use Repository to fetch cached series listing.
         */
        $sermons = $this->sermonRepository->getSermonsBySeries($series_name);

        return view('sermons.series', [
            'sermons' => $sermons,
            'heading' => 'Sermon Series: '.$series_name,
            'description' => 'Browse all sermons in the "'.$series_name.'" series from Crockenhill Baptist Church.',
        ]);
    }

    public function getService(string $service): View
    {
        /**
         * Performance Optimization: Use Repository to fetch cached service listing.
         */
        $sermons = $this->sermonRepository->getSermonsByService($service);

        $serviceLabel = match ($service) {
            'morning' => 'Sunday Morning',
            'evening' => 'Sunday Evening',
            default => Str::title($service),
        };

        return view('sermons.service', [
            'sermons' => $sermons,
            'service' => $service,
            'heading' => $serviceLabel.' Services',
            'description' => "Listen to recent {$serviceLabel} sermons from Crockenhill Baptist Church.",
        ]);
    }

    public function showWithDate(
        int $year,
        int $month,
        Sermon $sermon,
        SermonPageContextService $pageContextService,
        SermonExposurePolicy $exposurePolicy
    ): View|RedirectResponse {
        if ($exposurePolicy->shouldRedirectGenericSermonRoute($sermon)) {
            return redirect()->to($exposurePolicy->publicUrl($sermon), 301);
        }

        abort_unless($sermon->content_type === SermonContentType::Sermon, 404);

        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->show($sermon, $pageContextService, $exposurePolicy);
    }
}
