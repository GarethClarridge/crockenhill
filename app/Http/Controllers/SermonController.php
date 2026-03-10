<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Services\SermonPageContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SermonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $distinct_dates = Sermon::query()
            ->whereSermon()
            ->select('date')
            ->distinct()
            ->orderBy('date', 'desc')
            ->limit(6)
            ->pluck('date');

        if ($distinct_dates->isNotEmpty()) {
            /**
             * Performance Optimization: Eager load preacherProfile and limit retrieved columns
             * to required fields for cards to reduce memory usage and DB I/O.
             */
            $latest_sermons = $this->publicSermonQuery()
                ->whereIn('date', $distinct_dates)
                ->orderBy('date', 'desc')
                ->orderBy('service', 'asc')
                ->get()
                ->groupBy(fn ($sermon) => $sermon->date->format('Y-m-d'));
        } else {
            $latest_sermons = collect();
        }

        return view('sermons.index', [
            'latest_sermons' => $latest_sermons,
            'heading' => 'Sermons',
            'description' => 'Listen to recent sermons from Crockenhill Baptist Church. Worshipping God, strengthening believers, and proclaiming Jesus Christ.',
        ]);
    }

    public function getAll(): View
    {
        $sermons = $this->publicSermonQuery()
            ->orderBy('date', 'desc')
            ->orderBy('service', 'asc')
            ->get()
            ->groupBy(function ($sermon) {
                return $sermon->date->format('Y-m-d');
            });

        return view('sermons.all', [
            'sermons' => $sermons,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Sermon $sermon, SermonPageContextService $pageContextService): View
    {
        $heading = $sermon->title;
        $pageContext = $pageContextService->build($sermon);

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

    public function getPreachers(): View
    {
        $page = Page::query()
            ->select(['id', 'slug', 'body'])
            ->where('slug', 'preachers')
            ->first();

        /**
         * Performance Optimization: Limits retrieved columns for preachers to required fields
         * (name and slug) to reduce memory usage.
         */
        $preachers = Preacher::active()
            ->select(['id', 'name', 'slug'])
            ->withCount([
                'sermons' => fn (Builder $query): Builder => $query->whereSermon(),
            ])
            ->orderByDesc('sermons_count')
            ->orderBy('name')
            ->get();

        return view('sermons.preachers', [
            'preachers' => $preachers,
            'heading' => 'Preachers',
            'description' => 'Preachers at Crockenhill Baptist Church.',
            'content' => $page ? $page->body : '',
        ]);
    }

    /**
     * Display sermons for a specific preacher.
     *
     * Performance Optimization: Eager loads 'preacherProfile' to prevent N+1 queries
     * when displaying sermon cards that access the preacher's name.
     */
    public function getPreacher(Preacher $preacher): View
    {
        /**
         * Performance Optimization: Eager load preacherProfile and limit retrieved columns
         * to required fields for cards.
         */
        $sermons = $preacher->sermons()
            ->whereSermon()
            ->select(['id', 'title', 'date', 'slug', 'service', 'preacher', 'preacher_id', 'series', 'reference', 'thumbnail_file_path', 'thumbnail_metadata', 'source_type'])
            ->with('preacherProfile:id,name,slug')
            ->orderBy('date', 'desc')
            ->get();

        return view('sermons.preacher', [
            'preacher' => $preacher,
            'sermons' => $sermons,
        ]);
    }

    public function getSerieses(SermonRepository $sermonRepository): View
    {
        $series = collect($sermonRepository->getSeriesForDisplay());

        return view('sermons.serieses', [
            'series' => $series,
        ]);
    }

    public function getSeries(string $series): View
    {
        $series_name = str_replace('-', ' ', Str::title($series));
        /**
         * Performance Optimization: Eager load preacherProfile and limit retrieved columns
         * to required fields for cards.
         */
        $sermons = $this->publicSermonQuery()
            ->where('series', $series_name)
            ->orderBy('date', 'desc')
            ->get();

        return view('sermons.series', [
            'sermons' => $sermons,
        ]);
    }

    public function getService(string $service): View
    {
        /**
         * Performance Optimization: Eager load preacherProfile and limit retrieved columns
         * to required fields for cards.
         */
        $sermons = $this->publicSermonQuery()
            ->where('service', $service)
            ->orderBy('date', 'desc')
            ->get();

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

    public function showWithDate(int $year, int $month, Sermon $sermon, SermonPageContextService $pageContextService): View
    {
        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->show($sermon, $pageContextService);
    }

    /**
     * Build the base query for public sermon listings and browse pages.
     *
     * @return Builder<Sermon>
     */
    private function publicSermonQuery(): Builder
    {
        return Sermon::query()
            ->whereSermon()
            ->select(['id', 'title', 'date', 'slug', 'service', 'preacher', 'preacher_id', 'series', 'reference', 'thumbnail_file_path', 'thumbnail_metadata', 'source_type'])
            ->with('preacherProfile:id,name,slug');
    }
}
