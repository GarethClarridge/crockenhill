<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Support\Facades\DB as FacadesDB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SermonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $distinct_dates = Sermon::select('date')
            ->distinct()
            ->orderBy('date', 'desc')
            ->limit(6)
            ->pluck('date');

        if ($distinct_dates->isNotEmpty()) {
            $latest_sermons = Sermon::whereIn('date', $distinct_dates)
                ->orderBy('date', 'desc')
                ->orderBy('service', 'asc')
                ->get()
                ->groupBy(fn ($sermon) => $sermon->date->format('Y-m-d'));
        } else {
            $latest_sermons = collect();
        }

        return view('sermons.index', [
            'latest_sermons' => $latest_sermons,
        ]);
    }

    public function getAll(): View
    {
        $sermons = Sermon::orderBy('date', 'desc')
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
    public function show(Sermon $sermon): View
    {
        $heading = $sermon->title;

        // Breadcrumbs removed
        // Example of how it might be handled in view or view composer:
        // $breadcrumbs = [
        //   ['url' => route('sermonIndex'), 'title' => 'Sermons'],
        // ];
        // if (isset($sermon->series) && $sermon->series !== '') {
        //   $breadcrumbs[] = ['url' => route('sermonSeries', Str::slug($sermon->series)), 'title' => $sermon->series];
        // }
        // $breadcrumbs[] = ['title' => $sermon->title, 'active' => true];

        return view('sermons.sermon', [
            'slug' => $sermon->slug,
            'heading' => $heading,
            'description' => '<meta name="description" content="'.$sermon->title.': a sermon preached at Crockenhill Baptist Church.">', // Used $sermon->title instead of $sermon->heading
            // 'breadcrumbs' => $breadcrumbs, // Removed
            'content' => '',
            'sermon' => $sermon,
        ]);
    }

    public function getPreachers(): View
    {
        $page = Page::where('slug', 'preachers')->first();

        $preachers_with_counts = Sermon::select('preacher', FacadesDB::raw('COUNT(*) as sermons_count'))
            ->groupBy('preacher')
            ->orderByDesc('sermons_count')
            ->orderBy('preacher', 'asc')
            ->get();

        $preacher_array = [];
        foreach ($preachers_with_counts as $preacher_data) {
            /** @phpstan-ignore-next-line */
            $preacher_array[$preacher_data->preacher] = [$preacher_data->sermons_count, $preacher_data->preacher];
        }

        return view('sermons.preachers', [
            'preachers' => $preacher_array,
            'heading' => 'Preachers',
            'description' => '<meta name="description" content="Preachers at Crockenhill Baptist Church.">',
            'content' => $page ? $page->body : '', // Add a check for $page
        ]);
    }

    public function getPreacher(string $preacher): View
    {
        $preacher_name = str_replace('-', ' ', Str::title($preacher));
        $sermons = Sermon::where('preacher', $preacher_name)
            ->orderBy('date', 'desc')
            ->get();

        return view('sermons.preacher', [
            'sermons' => $sermons,
        ]);
    }

    public function getSerieses(): View
    {
        $series = Sermon::select('series')->distinct()->get();

        return view('sermons.serieses', [
            'series' => $series,
        ]);
    }

    public function getSeries(string $series): View
    {
        $series_name = str_replace('-', ' ', Str::title($series));
        $sermons = Sermon::where('series', $series_name)
            ->orderBy('date', 'desc')
            ->get();

        return view('sermons.series', [
            'sermons' => $sermons,
        ]);
    }

    public function getService(string $service): View
    {
        $sermons = Sermon::where('service', $service)
            ->orderBy('date', 'desc')
            ->get();

        return view('sermons.service', [
            'sermons' => $sermons,
        ]);
    }

    /**
     * Display the specified resource with date validation.
     */
    public function showWithDate(int $year, int $month, Sermon $sermon): View
    {
        // Validate that the sermon's date matches the URL parameters
        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->show($sermon);
    }
}
