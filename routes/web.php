<?php

use App\Http\Controllers\Admin\CalendarAdminController;
use App\Http\Controllers\Admin\SermonAdminController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
// Import all necessary controllers
use App\Http\Controllers\MeetingController; // Added this line
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PodcastFeedController;
use App\Http\Controllers\SermonAssetController;
use App\Http\Controllers\SermonController;
use App\Http\Controllers\SitemapController;
use App\Models\Meeting;
use Illuminate\Support\Facades\Route;

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
Route::resource('meetings', MeetingController::class)
    ->except(['show'])
    ->middleware('auth');

// Calendar routes
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
Route::get('/calendar/uncategorized', [CalendarController::class, 'uncategorized'])->name('calendar.uncategorized');
Route::get('/meetings/{meeting}/events', [CalendarController::class, 'eventsForMeeting'])->name('meetings.events');

// Community meetings - always loads a Meeting (which gets content from its related Page)
Route::get('/community/{meeting:slug}', [MeetingController::class, 'show'])->name('meetings.show');

// Sermon routes
Route::group(['prefix' => 'christ/sermons'], function () {
    Route::get('/', [SermonController::class, 'index'])->name('sermonIndex');
    Route::get('all', [SermonController::class, 'getAll'])->name('allSermons');
    Route::get('preachers', [SermonController::class, 'getPreachers'])->name('getPreachers');
    Route::get('preachers/{preacher:slug}', [SermonController::class, 'getPreacher'])->name('getPreacher');
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
    Route::get('/{year}/{month}/{sermon:slug}/edit', [SermonAdminController::class, 'editWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->middleware('auth')
        ->name('editSermonWithDate');
    Route::post('/{year}/{month}/{sermon:slug}/edit', [SermonAdminController::class, 'updateWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->middleware('auth')
        ->name('updateSermonWithDate');
    Route::post('/{year}/{month}/{sermon:slug}/delete', [SermonAdminController::class, 'destroyWithDate'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->middleware('auth')
        ->name('destroySermonWithDate');

    // Audio serving route
    Route::get('/{sermon:slug}/audio', [SermonAssetController::class, 'serveAudio'])->name('serveSermonAudio');

    // Thumbnail serving route
    Route::get('/{sermon:slug}/thumbnail', [SermonAssetController::class, 'serveThumbnail'])->name('serveSermonThumbnail');

    // Fallback slug-only routes
    Route::get('/{sermon:slug}', [SermonController::class, 'show'])->name('showSermon');
    Route::get('/{sermon:slug}/edit', [SermonAdminController::class, 'edit'])->middleware('auth')->name('editSermon');
    Route::post('/{sermon:slug}/edit', [SermonAdminController::class, 'update'])->middleware('auth')->name('updateSermon');
    Route::post('/{sermon:slug}/delete', [SermonAdminController::class, 'destroy'])->middleware('auth')->name('destroySermon');
});

// Members routes

// Add Livewire authentication routes
Route::middleware('guest')->group(function () {
    Route::view('login', 'auth.login')->name('login');
    Route::view('register', 'auth.register')->name('register');
    Route::view('forgot-password', 'auth.forgot-password')->name('password.request');
    Route::get('reset-password/{token}', function ($token) {
        return view('auth.reset-password', ['token' => $token]);
    })->name('password.reset');
});
Route::get('verify-email', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

// Admin routes (Livewire)
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Redirect /admin to members home - all admin functions accessible from there
    Route::redirect('/', '/church/members')->name('dashboard');

    // Pages
    Route::get('/pages', App\Livewire\Admin\Pages\ListPages::class)->name('pages.index');
    Route::get('/pages/create', App\Livewire\Admin\Pages\CreatePage::class)->name('pages.create');
    Route::get('/pages/{page:slug}/edit', App\Livewire\Admin\Pages\EditPage::class)->name('pages.edit');

    // Meetings
    Route::get('/meetings', App\Livewire\Admin\Meetings\ListMeetings::class)->name('meetings.index');
    Route::get('/meetings/create', App\Livewire\Admin\Meetings\CreateMeeting::class)->name('meetings.create');
    Route::get('/meetings/{meeting:slug}/edit', App\Livewire\Admin\Meetings\EditMeeting::class)->name('meetings.edit');

    // Sermons
    Route::get('/sermons', App\Livewire\Admin\Sermons\ListSermons::class)->name('sermons.index');
    Route::get('/sermons/{sermon:slug}/edit', App\Livewire\Admin\Sermons\EditSermon::class)->name('sermons.edit');

    // Preachers
    Route::get('/preachers', App\Livewire\Admin\Preachers\ListPreachers::class)->name('preachers.index');
    Route::get('/preachers/create', App\Livewire\Admin\Preachers\CreatePreacher::class)->name('preachers.create');
    Route::get('/preachers/{preacher:slug}/edit', App\Livewire\Admin\Preachers\EditPreacher::class)->name('preachers.edit');

    // Calendar Events
    Route::get('/calendar-events', App\Livewire\Admin\CalendarEvents\ListCalendarEvents::class)->name('calendar-events.index');
    Route::get('/calendar-events/{calendarEvent}/edit', App\Livewire\Admin\CalendarEvents\EditCalendarEvent::class)->name('calendar-events.edit');

    // Users
    Route::get('/users', App\Livewire\Admin\Users\ListUsers::class)->name('users.index');
    Route::get('/users/create', App\Livewire\Admin\Users\CreateUser::class)->name('users.create');
    Route::get('/users/{user}/edit', App\Livewire\Admin\Users\EditUser::class)->name('users.edit');
});

// Redirect old admin routes to new admin
Route::middleware('auth')->group(function () {
    Route::redirect('/church/members/pages', '/admin/pages');
    Route::redirect('/church/members/pages/create', '/admin/pages/create');
    Route::redirect('/church/members/meetings', '/admin/meetings');
    Route::redirect('/church/members/meetings/create', '/admin/meetings/create');
    Route::get('/church/members/pages/{page}/edit', fn (Page $page) => redirect("/admin/pages/{$page->slug}/edit"));
    Route::get('/church/members/meetings/{meeting}/edit', fn (Meeting $meeting) => redirect("/admin/meetings/{$meeting->slug}/edit"));
});

Route::group(['middleware' => 'auth', 'prefix' => 'church/members'], function () {
    Route::get('', MemberController::class)->name('memberHome');
    // Pages resource removed - now handled by Filament at /admin/pages
    // Meetings resource removed - now handled by Filament at /admin/meetings

    // Calendar admin routes
    Route::middleware('admin')->group(function () {
        Route::get('calendar/uncategorized', [CalendarAdminController::class, 'uncategorizedEvents'])->name('admin.calendar.uncategorized');
        Route::post('calendar/categorize', [CalendarAdminController::class, 'categorizeEvent'])->name('admin.calendar.categorize');
        Route::get('calendar/patterns', [CalendarAdminController::class, 'patternManagement'])->name('admin.calendar.patterns');
        Route::post('calendar/sync', [CalendarAdminController::class, 'syncCalendar'])->name('admin.calendar.sync');
    });

    // Unified media upload route (replaces smart-upload)
    Route::get('sermon-upload', [SermonAdminController::class, 'upload'])->name('admin.sermon-upload.create');
    Route::post('sermon-upload', [SermonAdminController::class, 'processMedia'])->name('admin.sermon-upload.store');
});

Route::get('phpinfo', fn () => app()->isLocal() ? phpinfo() : abort(404))->middleware('admin');

// Sitemap route
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// Permanent Redirects — sourced from config/redirects.php
foreach (config('redirects') as $from => $to) {
    Route::permanentRedirect($from, $to);
}

// Catch-all dynamic page routes (these must be last!)
Route::get('/{area}', [PageController::class, 'showPage'])->name('pages.showArea'); // Area-only route without trailing slash
Route::get('/{area}/{slug}', [PageController::class, 'show'])->name('pages.showPublic');

if (app()->isLocal()) {
    Route::get('500', function () {
        abort(500);
    });
}

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->name('logout');

// Add the email verification route
Route::get('verify-email/{id}/{hash}', [AuthenticatedSessionController::class, 'verifyEmail'])
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');
