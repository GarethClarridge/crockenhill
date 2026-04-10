<?php

use App\Http\Controllers\Admin\CalendarAdminController;
use App\Http\Controllers\Admin\SermonAdminController;
use App\Http\Controllers\Admin\SermonThumbnailCandidateController;
use App\Http\Controllers\Admin\ServiceSectionCandidateMediaController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChildrensCornerController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PodcastFeedController;
use App\Http\Controllers\PublicSongListController;
use App\Http\Controllers\SermonAssetController;
use App\Http\Controllers\SermonController;
use App\Http\Controllers\SitemapController;
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

// Full width pages
Route::view('/christ', 'full-width-pages.christ')->name('christ');
Route::get('/christ/childrens-corner', [ChildrensCornerController::class, 'index'])->middleware('childrens-corner.access')->name('childrens-corner.index');
Route::get('/christ/childrens-corner/{sermon:slug}', [ChildrensCornerController::class, 'show'])->middleware('childrens-corner.access')->name('childrens-corner.show');
Route::view('/church', 'full-width-pages.church')->name('church');
Route::view('/community', 'full-width-pages.community')->name('community');

// High priority redirect that needs to be processed early
Route::permanentRedirect('whats-on/buzz-club', '/community/buzz-club');

// Meeting routes
Route::resource('meetings', MeetingController::class)
    ->only(['index', 'update', 'destroy'])
    ->middleware(['auth', 'verified', 'admin']);

// Calendar routes
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
Route::get('/calendar/uncategorized', [CalendarController::class, 'uncategorized'])->name('calendar.uncategorized');
Route::get('/meetings/{meeting}/events', [CalendarController::class, 'eventsForMeeting'])->name('meetings.events');

// Community meetings - always loads a Meeting (which gets content from its related Page)
Route::get('/community/{meeting:slug}', [MeetingController::class, 'show'])->name('meetings.show');

// Sermon routes
Route::group(['prefix' => 'christ/sermons'], function () {
    Route::get('/', [SermonController::class, 'index'])->name('sermons.index');
    Route::get('all', [SermonController::class, 'all'])->name('sermons.all');
    Route::get('preachers', [SermonController::class, 'preachers'])->name('sermons.preachers');
    Route::get('preachers/{preacher:slug}', [SermonController::class, 'preacher'])->name('sermons.preacher');
    Route::get('series', [SermonController::class, 'series'])->name('sermons.series');
    Route::get('series/{series}', [SermonController::class, 'seriesShow'])->name('sermons.series.show');

    // Podcast RSS feeds (must be before {service} catch-all)
    Route::get('{service}/feed', [PodcastFeedController::class, 'show'])
        ->where('service', 'morning|evening')
        ->name('podcast.feed');

    Route::get('{service}', [SermonController::class, 'service'])->where('service', 'morning|evening|other')->name('sermons.service');

    // Date-based sermon routes (must come before slug-only routes)
    Route::get('/{year}/{month}/{sermon:slug}', [SermonController::class, 'showDated'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->name('sermons.show.dated');

    // Audio serving route
    Route::get('/{sermon:slug}/audio', [SermonAssetController::class, 'serveAudio'])
        ->middleware('throttle:media-audio')
        ->name('sermons.audio');

    // Thumbnail serving route
    Route::get('/{sermon:slug}/thumbnail', [SermonAssetController::class, 'serveThumbnail'])
        ->middleware('throttle:media-thumbnail')
        ->name('sermons.thumbnail');

    Route::get('/{sermon:slug}/thumbnail/card', [SermonAssetController::class, 'serveCardThumbnail'])
        ->middleware('throttle:media-thumbnail')
        ->name('sermons.thumbnail.card');

    // Fallback slug-only routes
    Route::get('/{sermon:slug}', [SermonController::class, 'show'])->name('sermons.show');
    Route::post('/{sermon:slug}/delete', [SermonAdminController::class, 'destroy'])->middleware(['auth', 'verified', 'admin'])->name('sermons.destroy');
});

// Members routes

// Add Livewire authentication routes
Route::middleware('guest')->group(function () {
    Route::view('login', 'auth.login', ['heading' => 'Login'])->name('login');
    Route::view('register', 'auth.register', ['heading' => 'Register'])->name('register');
    Route::view('forgot-password', 'auth.forgot-password', ['heading' => 'Forgot Password'])->name('password.request');
    Route::get('reset-password/{token}', function ($token) {
        return view('auth.reset-password', ['token' => $token, 'heading' => 'Reset Password']);
    })->name('password.reset');
});
Route::get('verify-email', function () {
    return view('auth.verify-email', ['heading' => 'Verify Email']);
})->middleware('auth')->name('verification.notice');

// Admin routes (Livewire)
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Redirect /admin to members home dashboard
    Route::redirect('/', '/church/members')->name('dashboard');

    // Calendar admin routes
    Route::get('/calendar/uncategorized', [CalendarAdminController::class, 'uncategorizedEvents'])->name('calendar.uncategorized');
    Route::post('/calendar/categorize', [CalendarAdminController::class, 'categorizeEvent'])->name('calendar.categorize');
    Route::get('/calendar/patterns', [CalendarAdminController::class, 'patternManagement'])->name('calendar.patterns');
    Route::post('/calendar/sync', [CalendarAdminController::class, 'syncCalendar'])->name('calendar.sync');

    // Sermon upload
    Route::get('/sermon-upload', [SermonAdminController::class, 'upload'])->name('sermon-upload.create');
    Route::post('/sermon-upload', [SermonAdminController::class, 'processMedia'])
        ->middleware('throttle:media-upload')
        ->name('sermon-upload.store');

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
    Route::get('/sermons/{sermon:slug}/thumbnails/{candidateId}/{variant}', [SermonThumbnailCandidateController::class, 'show'])
        ->where('variant', 'overlay|card|plain')
        ->name('sermons.thumbnails.preview');

    // Church Services
    Route::get('/services', App\Livewire\Admin\ChurchServices\ListChurchServices::class)->name('services.index');
    Route::get('/services/create', App\Livewire\Admin\ChurchServices\ManageChurchService::class)->name('services.create');
    Route::get('/services/upload', App\Livewire\Admin\ChurchServices\UploadChurchService::class)->name('services.upload');
    Route::get('/services/inbound-emails', App\Livewire\Admin\ChurchServices\ReviewInboundEmails::class)->name('services.inbound-emails');
    Route::get('/services/submit-email', App\Livewire\Admin\ChurchServices\SubmitEmailText::class)->name('services.submit-email');
    Route::get('/services/review', App\Livewire\Admin\ChurchServices\ServiceReviewDashboard::class)->name('services.review');
    Route::get('/services/songs', App\Livewire\Admin\ChurchServices\ListSongs::class)->name('services.songs.index');
    Route::get('/services/songs/{song}', App\Livewire\Admin\ChurchServices\ShowSong::class)->name('services.songs.show');
    Route::get('/services/section-publications', App\Livewire\Admin\ChurchServices\ListSectionPublications::class)->name('services.section-publications');
    Route::get('/services/section-publications/{serviceSection}/preview/audio', [ServiceSectionCandidateMediaController::class, 'serveAudio'])
        ->name('services.section-publications.preview-audio');
    Route::get('/services/section-publications/{serviceSection}/preview/video', [ServiceSectionCandidateMediaController::class, 'serveVideo'])
        ->name('services.section-publications.preview-video');
    Route::get('/services/processing/review', App\Livewire\Admin\ChurchServices\ProcessingReviewList::class)->name('services.processing.review.index');
    Route::get('/services/processing/{processingLog}/review', App\Livewire\Admin\ChurchServices\ProcessingReview::class)->name('services.processing.review');
    Route::get('/services/{churchService}/edit', App\Livewire\Admin\ChurchServices\ManageChurchService::class)->name('services.edit');
    Route::get('/services/{churchService}', App\Livewire\Admin\ChurchServices\ShowChurchService::class)->name('services.show');

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

// "Members only" currently means "has a user account", not "verified/invited member".
Route::middleware('auth')->prefix('church/members')->group(function () {
    Route::get('', MemberController::class)->name('memberHome');
});

Route::middleware('auth')->prefix('church/songs')->name('church.songs.')->group(function () {
    Route::get('', [PublicSongListController::class, 'index'])->name('index');
    Route::get('{song:slug}', [PublicSongListController::class, 'show'])->name('show');
});

// Sitemap route
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// Permanent Redirects — sourced from config/redirects.php
foreach (config('redirects') as $from => $to) {
    Route::permanentRedirect($from, $to);
}

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->name('logout');

Route::get('verify-email/{id}/{hash}', [AuthenticatedSessionController::class, 'verifyEmail'])
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

if (app()->isLocal()) {
    Route::get('500', function () {
        abort(500);
    });

    Route::view('/dev/components', 'dev.components')->name('dev.components');

    Route::get('phpinfo', fn () => phpinfo())->middleware(['auth', 'admin']);
}

// Catch-all dynamic page routes (these must be last!)
Route::get('/{area}', [PageController::class, 'showPage'])->where('area', '(?!_dusk)[^/]+')->name('pages.showArea');
Route::get('/{area}/{slug}', [PageController::class, 'show'])->where('area', '(?!_dusk)[^/]+')->name('pages.showPublic');
