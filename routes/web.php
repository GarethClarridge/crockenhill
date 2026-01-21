<?php

use App\Http\Controllers\Admin\CalendarAdminController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
// Import all necessary controllers
use App\Http\Controllers\MeetingController; // Added this line
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PodcastFeedController;
use App\Http\Controllers\SermonController;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::view('/', 'full-width-pages.home')->name('Home');

// Special pages route
Route::view('/christmas', 'full-width-pages.christmas')->name('christmas');
Route::view('/easter', 'full-width-pages.easter')->name('easter');

// Full width pages
Route::view('/christ', 'full-width-pages.christ')->name('christ');
Route::view('/church', 'full-width-pages.church')->name('church');
Route::view('/community', 'full-width-pages.community')->name('community');

// High priority redirect that needs to be processed early
Route::permanentRedirect('whats-on/buzz-club', '/community/buzz-club');

// Meeting routes
Route::resource('meetings', MeetingController::class);

// Calendar routes
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
Route::get('/calendar/uncategorized', [CalendarController::class, 'uncategorized'])->name('calendar.uncategorized');
Route::get('/meetings/{meeting}/events', [CalendarController::class, 'eventsForMeeting'])->name('meetings.events');

// Community meetings - always loads a Meeting (which gets content from its related Page)
Route::get('/community/{meeting:slug}', [MeetingController::class, 'show'])->name('meetings.show');

// Sermon routes
Route::group(['prefix' => 'christ/sermons'], function () {
    Route::get('/', [SermonController::class, 'index'])->name('sermonIndex');
    Route::get('/upload', [SermonController::class, 'upload'])->name('sermonUpload');
    Route::post('/upload', [SermonController::class, 'processMedia'])->name('sermonPost');
    Route::get('all', [SermonController::class, 'getAll'])->name('allSermons');
    Route::get('preachers', [SermonController::class, 'getPreachers'])->name('getPreachers');
    Route::get('preachers/{preacher}', [SermonController::class, 'getPreacher'])->name('getPreacher');
    Route::get('series', [SermonController::class, 'getSerieses'])->name('getSerieses');
    Route::get('series/{series}', [SermonController::class, 'getSeries'])->name('getSeries');

    // Podcast RSS feeds (must be before {service} catch-all)
    Route::get('{service}/feed', [PodcastFeedController::class, 'show'])
        ->where('service', 'morning|evening')
        ->name('podcast.feed');

    Route::get('{service}', [SermonController::class, 'getService'])->where('service', 'morning|evening|other')->name('getService');

    // Date-based sermon routes (must come before slug-only routes)
    Route::get('/{year}/{month}/{sermon:slug}', [SermonController::class, 'showWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->name('showSermonWithDate');
    Route::get('/{year}/{month}/{sermon:slug}/edit', [SermonController::class, 'editWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->name('editSermonWithDate');
    Route::post('/{year}/{month}/{sermon:slug}/edit', [SermonController::class, 'updateWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->name('updateSermonWithDate');
    Route::post('/{year}/{month}/{sermon:slug}/delete', [SermonController::class, 'destroyWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->name('destroySermonWithDate');

    // Audio serving route
    Route::get('/{sermon:slug}/audio', [SermonController::class, 'serveAudio'])->name('serveSermonAudio');

    // Thumbnail serving route
    Route::get('/{sermon:slug}/thumbnail', [SermonController::class, 'serveThumbnail'])->name('serveSermonThumbnail');

    // Fallback slug-only routes
    Route::get('/{sermon:slug}', [SermonController::class, 'show'])->name('showSermon');
    Route::get('/{sermon:slug}/edit', [SermonController::class, 'edit'])->name('editSermon');
    Route::post('/{sermon:slug}/edit', [SermonController::class, 'update'])->name('updateSermon');
    Route::post('/{sermon:slug}/delete', [SermonController::class, 'destroy'])->name('destroySermon');
});

// Members routes

// Add Livewire authentication routes using string syntax for Blade views
Route::get('login', function () {
    return view('auth.login');
})->name('login');
Route::get('register', function () {
    return view('auth.register');
})->name('register');
Route::get('forgot-password', function () {
    return view('auth.forgot-password');
})->name('password.request');
Route::get('reset-password/{token}', function ($token) {
    return view('auth.reset-password', ['token' => $token]);
})->name('password.reset');
Route::get('verify-email', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

// Redirect old admin routes to Filament
Route::middleware('auth')->group(function () {
    Route::get('/admin', fn () => redirect('/admin/pages'));
    Route::get('/church/members/pages', fn () => redirect('/admin/pages'));
    Route::get('/church/members/pages/create', fn () => redirect('/admin/pages/create'));
    Route::get('/church/members/pages/{page}/edit', fn (Page $page) => redirect("/admin/pages/{$page->slug}/edit"));
    // Redirect meeting admin routes to Filament
    Route::get('/church/members/meetings', fn () => redirect('/admin/meetings'));
    Route::get('/church/members/meetings/create', fn () => redirect('/admin/meetings/create'));
    Route::get('/church/members/meetings/{meeting}/edit', fn (Meeting $meeting) => redirect("/admin/meetings/{$meeting->slug}/edit"));
});

Route::group(['middleware' => 'auth', 'prefix' => 'church/members'], function () {
    Route::get('', MemberController::class)->name('memberHome');
    // Pages resource removed - now handled by Filament at /admin/pages
    Route::resource('sermons', SermonController::class);
    // Meetings resource removed - now handled by Filament at /admin/meetings

    // Calendar admin routes
    Route::get('calendar/uncategorized', [CalendarAdminController::class, 'uncategorizedEvents'])->name('admin.calendar.uncategorized');
    Route::post('calendar/categorize', [CalendarAdminController::class, 'categorizeEvent'])->name('admin.calendar.categorize');
    Route::get('calendar/patterns', [CalendarAdminController::class, 'patternManagement'])->name('admin.calendar.patterns');
    Route::post('calendar/sync', [CalendarAdminController::class, 'syncCalendar'])->name('admin.calendar.sync');

    // Unified media upload route (replaces smart-upload)
    Route::get('sermon-upload', [SermonController::class, 'upload'])->name('admin.sermon-upload.create');
    Route::post('sermon-upload', [SermonController::class, 'processMedia'])->name('admin.sermon-upload.store');
});

Route::get('phpinfo', fn () => phpinfo())->middleware('admin');

// Sitemap route
Route::get('/sitemap.xml', function () {
    $sitemapPath = public_path('sitemap.xml');

    $generateSitemap = function () use ($sitemapPath) {
        Sitemap::create()
            // Static high-priority URLs
            ->add(Url::create('/')->setPriority(1.0)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/christ')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/church')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/community')->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/calendar')->setPriority(0.5)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY))
            ->add(Url::create('/christ/sermons')->setPriority(0.8)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY))

            // Dynamic content via Sitemapable models
            ->add(Sermon::all())
            ->add(Page::where('admin', 'no')->get()) // Only include non-admin pages
            ->add(Meeting::all())

            ->writeToFile($sitemapPath);

        return true;
    };

    // Laravel 12 flexible cache: fresh for 1 day, stale for 2 days
    Cache::flexible('sitemap', [86400, 172800], $generateSitemap);

    // Regenerate if file is missing (e.g., manually deleted)
    if (! file_exists($sitemapPath)) {
        $generateSitemap();
    }

    return response(file_get_contents($sitemapPath), 200, [
        'Content-Type' => 'application/xml',
    ]);
})->name('sitemap');

// Permanent Redirects (fixed with absolute paths)
// Specific whats-on/* redirects must come before general whats-on redirect
Route::permanentRedirect('whats-on/1150', '/community/1150');
Route::permanentRedirect('whats-on/adventurers', '/community/adventurers');
Route::permanentRedirect('whats-on/babytalk', '/community/baby-talk');
Route::permanentRedirect('whats-on/biblestudy', '/community/bible-study');
Route::permanentRedirect('whats-on/carolsatthechequers', '/community/carols-at-the-chequers');
Route::permanentRedirect('whats-on/christianityexplored', '/community/christianity-explored');
Route::permanentRedirect('whats-on/coffeecup', '/community/coffee-cup');
Route::permanentRedirect('whats-on/sunday', '/community/sunday-mornings');

Route::permanentRedirect('aboutus', '/church');
Route::permanentRedirect('contacttus', '/');
Route::permanentRedirect('links', '/church/links');
Route::permanentRedirect('whatson', '/community');
Route::permanentRedirect('whats-on', '/community');
Route::permanentRedirect('where', '/church/find-us');
Route::permanentRedirect('aboutus/history', '/church/history');
Route::permanentRedirect('aboutus/pastor', '/church/pastor');
Route::permanentRedirect('aboutus/statementoffaith', '/church/statement-of-faith');
Route::permanentRedirect('aboutus/whatwebelieve', '/church/what-we-believe');
Route::permanentRedirect('whatson/1150', '/community/1150');
Route::permanentRedirect('whatson/adventurers', '/community/adventurers');
Route::permanentRedirect('whatson/babytalk', '/community/baby-talk');
Route::permanentRedirect('whatson/biblestudy', '/community/bible-study');
Route::permanentRedirect('whatson/buzzclub', '/community/buzz-club');
Route::permanentRedirect('whatson/carolsatthechequers', '/community/carols-at-the-chequers');
Route::permanentRedirect('whatson/christianityexplored', '/community/christianity-explored');
Route::permanentRedirect('whatson/coffeecup', '/community/coffee-cup');
Route::permanentRedirect('whatson/sunday', '/community/sunday-mornings');

Route::permanentRedirect('about-us', '/church');
Route::permanentRedirect('about-us/history', '/church/history');
Route::permanentRedirect('about-us/pastor', '/church/pastor');
Route::permanentRedirect('about-us/links', '/church/links');
Route::permanentRedirect('about-us/statementoffaith', '/church/statement-of-faith');
Route::permanentRedirect('about-us/whatwebelieve', '/church/what-we-believe');
Route::permanentRedirect('about-us/privacy-policy', '/church/privacy-policy');
Route::permanentRedirect('about-us/safeguarding-policy', '/church/safeguarding-policy');

Route::permanentRedirect('buzz-club', '/community/buzz-club');
Route::permanentRedirect('messy-church', '/community/messy-church');
Route::permanentRedirect('reopening', '/attending-in-person');
Route::permanentRedirect('christianity-explored', '/community/christianity-explored');

Route::permanentRedirect('online', '/');
Route::permanentRedirect('resources', '/');

// Catch-all dynamic page routes (these must be last!)
Route::get('/{area}', [PageController::class, 'showPage'])->name('pages.showArea'); // Area-only route without trailing slash
Route::get('/{area}/{slug}', [PageController::class, 'show'])->name('pages.showPublic');

Route::get('500', function () {
    abort(500);
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->name('logout');

// Add the email verification route
Route::get('verify-email/{id}/{hash}', [AuthenticatedSessionController::class, 'verifyEmail'])
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');
