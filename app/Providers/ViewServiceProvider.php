<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\SermonService;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Request $request): void
    {
        View::composer('includes.footer', function ($view) {
            // get the latest sermons
            $morning = Sermon::where('service', SermonService::MORNING->value)
                ->orderBy('date', 'desc')->first();
            $evening = Sermon::where('service', SermonService::EVENING->value)
                ->orderBy('date', 'desc')->first();

            // and create the view composer
            $view->with('morning', $morning);
            $view->with('evening', $evening);
        });

        View::composer('includes.photo-selector', function ($view) {
            $photo_directory = '/images/photos';
            $public_photo_directory = public_path().$photo_directory;
            $photos = array_diff(scandir($public_photo_directory), ['..', '.']);

            $view->with('photo_directory', $photo_directory);
            $view->with('photos', $photos);
        });

        View::composer('layouts/page', function ($view) {
            // User
            $user = Auth::user();

            // Check if a page was passed from the controller
            $page = $view->page ?? null;

            if ($page) {
                // Use the page data from the controller
                $description = '<meta name="description" content="'.$page->description.'">';
                $heading = $page->heading;
                $content = htmlspecialchars_decode($page->body);
                $headingpicture = $page->heading_image_url;
                $area = $page->area->value;
                $slug = $page->slug;
                $links = Page::where('area', $area)
                    ->where('slug', '!=', $slug)
                    ->where('slug', '!=', 'privacy-policy')
                    ->where('admin', '!=', 'yes')
                    ->orderBy(DB::raw('RAND()'))
                    ->take(5)
                    ->get();
                $view->with([
                    'description' => $description,
                    'heading' => $heading,
                    'content' => $content,
                    'headingpicture' => $headingpicture,
                    'area' => $area,
                    'slug' => $slug,
                    'links' => $links,
                ]);
            } else {
                // Fall back to existing URL-based logic
                // Set name, slug and area from url
                if (request()->segment(3)) {
                    $name = request()->segment(3);
                    $slug = request()->segment(2);
                    $area = request()->segment(1);
                } elseif (request()->segment(2)) {
                    $name = null;
                    $slug = request()->segment(2);
                    $area = request()->segment(1);
                } else {
                    $name = null;
                    $slug = request()->segment(1);
                    $area = request()->segment(1);
                }

                // Initialize variables
                $description = '';
                $heading = '';
                $headingpicture = '';
                $content = '';
                $links = collect();
                $pages = collect();

                // Songs - Note: Song model doesn't exist, so this section is commented out
                // if (request()->segment(3) == 'songs' && request()->segment(5)) {
                //   // Look up song in songs table of database
                //   $songnumber = request()->segment(4);
                //   $song = \App\Models\Song::where('id', $songnumber)->first();
                //   // ... rest of song logic
                // }

                // Sermons
                if (request()->segment(2) == 'sermons' && request()->segment(5)) {
                    $sermon_slug = request()->segment(5);
                    $month = request()->segment(4);
                    $year = request()->segment(3);

                    $sermon = Sermon::where('slug', $sermon_slug)
                        ->whereMonth('date', '=', $month)
                        ->whereYear('date', '=', $year)
                        ->first();

                    if ($sermon) {
                        $heading = $sermon->title;

                        // Heading picture
                        $headingpicture = '/images/headings/large/'.$sermon_slug.'.webp';

                        // Find relevant links
                        $links = Page::where('area', $slug)
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', 'homepage')
                            ->orderBy(DB::raw('RAND()'))
                            ->take(5)
                            ->get();

                        // Description
                        $description = '<meta name="description" content="'.$heading.'">';
                    }
                }

                // Auth
                elseif ((request()->segment(1) == 'login')
                  || (request()->segment(1) == 'register')
                  || (request()->segment(1) == 'password')
                ) {

                    $area = 'Members';

                    $heading = ucwords(str_replace('-', ' ', $slug));

                    // Heading picture
                    $headingpicture = '/images/headings/large/'.$area.'.webp';

                    // Find relevant links
                    $links = Page::where('area', $area)
                        ->where('slug', '!=', $area)
                        ->where('slug', '!=', 'homepage')
                        ->orderBy(DB::raw('RAND()'))
                        ->take(5)
                        ->get();

                    // Description
                    $description = '<meta name="description" content="'.$heading.'">';
                }

                // Level 3
                elseif (request()->segment(3)) {

                    // Description
                    $description = '<meta name="description" content="'.$slug.': '.$name.'">';

                    // Heading
                    if ($view->heading) {
                        $heading = $view->heading;
                    } else {
                        $heading = str_replace('-', ' ', ucwords(request()->segment(count(request()->segments()))));
                    }

                    // Heading picture
                    $headingpicture = '/images/headings/large/'.$slug.'.webp';

                    // Links
                    if (request()->segment(2) == 'sermons') {
                        $links = Page::where('area', 'sermons')
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->orderBy('slug', 'asc')
                            ->get();
                    } elseif (request()->segment(2) == 'members') {
                        $links = Page::where('area', 'sermons')
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->where('admin', '!=', 'yes')
                            ->orderBy('slug', 'asc')
                            ->get();
                    } else {
                        $links = Page::where('area', $area)
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->where('slug', '!=', 'privacy-policy')
                            ->where('admin', '!=', 'yes')
                            ->orderBy('slug', 'asc')
                            ->get();
                    }
                }

                // Level 2
                elseif (request()->segment(2)) {

                    // Load page - filter by both area and slug
                    $page = Page::where('slug', $slug)->where('area', $area)->first();
                    if ($page) {
                        // Description
                        $description = '<meta name="description" content="'.$page->description.'">';

                        // Heading
                        $heading = $page->heading;

                        // Content
                        $content = htmlspecialchars_decode($page->body);

                        // Heading picture - prefer media library, fall back to legacy
                        $headingpicture = $page->heading_image_url ?? '/images/headings/large/'.$slug.'.webp';
                    } else {
                        $description = null;
                        $heading = null;

                        // Heading picture - legacy path for non-page content
                        $headingpicture = '/images/headings/large/'.$slug.'.webp';
                    }

                    // Links
                    if (request()->segment(2) == 'sermons') {
                        $links = Page::where('area', 'sermons')
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->where('admin', '!=', 'yes')
                            ->orderBy('slug', 'asc')
                            ->get();
                    } elseif (request()->segment(2) == 'members') {
                        $links = Page::where('area', 'sermons')
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->where('admin', '!=', 'yes')
                            ->orderBy('slug', 'asc')
                            ->get();
                    } elseif (request()->segment(1) == 'community') {
                        // Community pages - meetings now pass page data directly from controller
                        // This block is now only used for non-meeting community pages
                        $links = Page::where('area', $area)
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->where('admin', '!=', 'yes')
                            ->orderBy('slug', 'asc')
                            ->get();
                    } else {
                        $links = Page::where('area', $area)
                            ->where('slug', '!=', $slug)
                            ->where('slug', '!=', $area)
                            ->where('slug', '!=', 'privacy-policy')
                            ->where('slug', '!=', 'attending-in-person')
                            ->where('slug', '!=', 'resources')
                            ->where('admin', '!=', 'yes')
                            ->orderBy('slug', 'asc')
                            ->get();
                    }
                }

                // Level 1
                else {

                    // Load page - filter by both area and slug
                    $page = Page::where('slug', $area)->where('area', $area)->first();

                    if ($page) {
                        // Description
                        $description = '<meta name="description" content="'.$page->description.'">';

                        // Heading
                        $heading = $page->heading;

                        // Content
                        $content = htmlspecialchars_decode($page->body);
                    } else {
                        // Default values if page is not found (e.g. for homepage handled by its own view logic)
                        $description = '<meta name="description" content="Welcome to our Church.">';
                        $heading = 'Welcome';
                        $content = '';
                    }

                    // Heading picture
                    $headingpicture = '/images/headings/large/'.$area.'.webp';

                    // Links
                    $links = Page::where('area', $area)
                        ->where('slug', '!=', $area)
                        ->where('slug', '!=', 'privacy-policy')
                        ->where('admin', '!=', 'yes')
                        ->orderBy(DB::raw('RAND()'))
                        ->take(5)
                        ->get();
                }

                $view->with([
                    'description' => $description,
                    'heading' => $heading,
                    'content' => $content,
                    'headingpicture' => $headingpicture,
                    'area' => $area ?? null,
                    'slug' => $slug ?? null,
                    'links' => $links,
                ]);
            }
        });

        View::composer(['full-width-pages.home', 'full-width-pages.community'], function ($view) {
            $pages = Page::all();

            $view->with([
                'pages' => $pages,
            ]);
        });

        View::composer('full-width-pages.church', function ($view) {
            $links = Page::where('area', 'church')
                ->where('slug', '!=', 'privacy-policy')
                ->where('slug', '!=', 'safeguarding-policy')
                ->where('admin', '!=', 'yes')
                ->get();

            $pages = Page::all();

            $view->with([
                'pages' => $pages,
                'links' => $links,
            ]);
        });
    }
}
