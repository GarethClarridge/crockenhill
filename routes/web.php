<?php

use App\Http\Controllers\Admin\CalendarAdminController;
use App\Http\Controllers\Admin\SermonAdminController;
use App\Http\Controllers\Admin\SermonThumbnailCandidateController;
use App\Http\Controllers\Admin\ServiceSectionCandidateMediaController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChildrensCornerController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PodcastFeedController;
use App\Http\Controllers\PublicSongListController;
use App\Http\Controllers\SermonAssetController;
use App\Http\Controllers\SermonController;
use App\Http\Controllers\SitemapController;
use App\Livewire\Admin\CalendarEvents\EditCalendarEvent;
use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ListSongs;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ReviewInbox;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\ShowSong;
use App\Livewire\Admin\ChurchServices\SubmitEmailText;
use App\Livewire\Admin\ChurchServices\UploadChurchService;
use App\Livewire\Admin\MediaUpload;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Livewire\Admin\Meetings\EditMeeting;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Pages\CreatePage;
use App\Livewire\Admin\Pages\EditPage;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Livewire\Admin\SermonSegmentReview;
use App\Livewire\Admin\Users\CreateUser;
use App\Livewire\Admin\Users\EditUser;
use App\Livewire\Admin\Users\ListUsers;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

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

Route::get('/', [LandingPageController::class, 'home'])->name('home');

// Special pages route
Route::view('/christmas', 'full-width-pages.christmas')->name('pages.christmas');

// Full width pages
Route::view('/christ', 'full-width-pages.christ')->name('pages.christ');
Route::get('/christ/childrens-corner', [ChildrensCornerController::class, 'index'])->middleware('childrens-corner.access')->name('childrens-corner.index');
Route::get('/christ/childrens-corner/{sermon:slug}', [ChildrensCornerController::class, 'show'])->middleware('childrens-corner.access')->name('childrens-corner.show');
Route::get('/church', [LandingPageController::class, 'church'])->name('pages.church');
Route::get('/community', [LandingPageController::class, 'community'])->name('pages.community');

// High priority redirect that needs to be processed early
Route::permanentRedirect('whats-on/buzz-club', '/community/buzz-club');

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
    Route::get('preachers/{preacher:slug}', [SermonController::class, 'preacher'])
        ->middleware('throttle:public-not-found')
        ->name('sermons.preacher');
    Route::get('series', [SermonController::class, 'series'])->name('sermons.series');
    Route::get('series/{series}', [SermonController::class, 'seriesShow'])
        ->middleware('throttle:public-not-found')
        ->name('sermons.series.show');

    // Podcast RSS feeds (must be before {service} catch-all)
    Route::get('{service}/feed', [PodcastFeedController::class, 'show'])
        ->where('service', 'morning|evening')
        ->name('podcast.feed');

    Route::get('{service}', [SermonController::class, 'service'])->where('service', 'morning|evening|other')->name('sermons.service');

    // Date-based sermon routes (must come before slug-only routes)
    Route::get('/{year}/{month}/{sermon:slug}', [SermonController::class, 'showDated'])
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])
        ->middleware('throttle:public-not-found')
        ->name('sermons.show.dated');

    // Audio serving route
    Route::get('/{sermon:slug}/audio', [SermonAssetController::class, 'serveAudio'])
        ->middleware('throttle:media-audio')
        ->name('sermons.audio');

    // No auth middleware: access control is enforced inside SermonAssetController
    // via canAccessChildrensCorner(), which also handles the public-release toggle.
    Route::get('/{sermon:slug}/video', [SermonAssetController::class, 'serveVideo'])
        ->middleware('throttle:media-video')
        ->name('sermons.video');

    // Thumbnail serving route
    Route::get('/{sermon:slug}/thumbnail', [SermonAssetController::class, 'serveThumbnail'])
        ->middleware('throttle:media-thumbnail')
        ->name('sermons.thumbnail');

    Route::get('/{sermon:slug}/thumbnail/plain', [SermonAssetController::class, 'servePlainThumbnail'])
        ->middleware('throttle:media-thumbnail')
        ->name('sermons.thumbnail.plain');

    Route::get('/{sermon:slug}/thumbnail/card', [SermonAssetController::class, 'serveCardThumbnail'])
        ->middleware('throttle:media-thumbnail')
        ->name('sermons.thumbnail.card');

    Route::get('/{sermon:slug}/transcript', [SermonAssetController::class, 'serveTranscript'])
        ->middleware('throttle:media-thumbnail')
        ->name('sermons.transcript');

    // Fallback slug-only routes
    Route::get('/{sermon:slug}', [SermonController::class, 'show'])
        ->middleware('throttle:public-not-found')
        ->name('sermons.show');
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
Route::view('verify-email', 'auth.verify-email', ['heading' => 'Verify Email'])
    ->middleware('auth')->name('verification.notice');

// Health dashboard (spatie/laravel-health) — admin-only monitoring surface.
// `/up` stays the unauthenticated boot-only probe for the load balancer.
Route::get('health', HealthCheckResultsController::class)
    ->middleware(['auth', 'verified', 'admin'])
    ->name('health');

// Admin routes (Livewire)
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Redirect /admin to members home dashboard
    Route::redirect('/', '/church/members')->name('dashboard');

    // Calendar admin routes
    Route::get('/calendar/uncategorized', [CalendarAdminController::class, 'uncategorizedEvents'])->name('calendar.uncategorized');
    Route::post('/calendar/categorize', [CalendarAdminController::class, 'categorizeEvent'])->name('calendar.categorize');
    Route::get('/calendar/patterns', [CalendarAdminController::class, 'patternManagement'])->name('calendar.patterns');
    Route::post('/calendar/sync', [CalendarAdminController::class, 'syncCalendar'])->name('calendar.sync');

    // Sermon upload (form renders the media-upload Livewire component; submission
    // is handled by the component / the /api/media/{type} API, not a POST route here)
    // Retired (P4): the recording upload moved under the services prefix.
    Route::redirect('/sermon-upload', '/admin/services/upload-recording')->name('sermon-upload.create');

    // Pages
    Route::get('/pages', ListPages::class)->name('pages.index');
    Route::get('/pages/create', CreatePage::class)->name('pages.create');
    Route::get('/pages/{page:slug}/edit', EditPage::class)->name('pages.edit');

    // Meetings
    Route::get('/meetings', ListMeetings::class)->name('meetings.index');
    Route::get('/meetings/create', CreateMeeting::class)->name('meetings.create');
    Route::get('/meetings/{meeting:slug}/edit', EditMeeting::class)->name('meetings.edit');

    // Sermons
    Route::get('/sermons', ListSermons::class)->name('sermons.index');
    Route::get('/sermons/{sermon:slug}/edit', EditSermon::class)->name('sermons.edit');
    Route::get('/sermons/{sermon:slug}/thumbnails/{candidateId}/{variant}', [SermonThumbnailCandidateController::class, 'show'])
        ->where('variant', 'overlay|card|plain')
        ->name('sermons.thumbnails.preview');

    // Church Services
    Route::get('/services', ListChurchServices::class)->name('services.index');
    Route::get('/services/inbox', ReviewInbox::class)->name('services.inbox');
    Route::get('/services/create', ManageChurchService::class)->name('services.create');
    Route::get('/services/upload', UploadChurchService::class)->name('services.upload');
    Route::get('/services/upload-recording', MediaUpload::class)->name('services.upload-recording');
    Route::get('/recordings/{processingLog:processing_id}/sermon-segment', SermonSegmentReview::class)
        ->name('recordings.sermon-segment');
    Route::get('/services/submit-email', SubmitEmailText::class)->name('services.submit-email');
    // Retired queue pages (P3.4/P5): triage moved to the review inbox, editing
    // to the service workbench. URLs 302 so bookmarks keep working.
    Route::redirect('/services/review', '/admin/services/inbox')->name('services.review');
    Route::redirect('/services/inbound-emails', '/admin/services/inbox?filter=emails')->name('services.inbound-emails');
    Route::redirect('/services/section-publications', '/admin/services/inbox?filter=sections')->name('services.section-publications');
    Route::redirect('/services/processing/review', '/admin/services/inbox?filter=segments')->name('services.processing.review.index');
    Route::get('/services/processing/{processingLog}/review', function (MediaProcessingLog $processingLog) {
        return redirect()->route('admin.recordings.sermon-segment', $processingLog->processing_id);
    })->name('services.processing.review');
    Route::get('/services/songs', ListSongs::class)->name('services.songs.index');
    Route::get('/services/songs/{song}', ShowSong::class)->name('services.songs.show');
    Route::get('/services/section-publications/{serviceSection}/preview/audio', [ServiceSectionCandidateMediaController::class, 'serveAudio'])
        ->name('services.section-publications.preview-audio');
    Route::get('/services/section-publications/{serviceSection}/preview/video', [ServiceSectionCandidateMediaController::class, 'serveVideo'])
        ->name('services.section-publications.preview-video');
    Route::get('/services/{churchService}/edit', ManageChurchService::class)->name('services.edit');
    Route::get('/services/{churchService}', ShowChurchService::class)->name('services.show');

    // Preachers
    Route::get('/preachers', ListPreachers::class)->name('preachers.index');
    Route::get('/preachers/create', CreatePreacher::class)->name('preachers.create');
    Route::get('/preachers/{preacher:slug}/edit', EditPreacher::class)->name('preachers.edit');

    // Calendar Events
    Route::get('/calendar-events', ListCalendarEvents::class)->name('calendar-events.index');
    Route::get('/calendar-events/{calendarEvent}/edit', EditCalendarEvent::class)->name('calendar-events.edit');

    // Users
    Route::get('/users', ListUsers::class)->name('users.index');
    Route::get('/users/create', CreateUser::class)->name('users.create');
    Route::get('/users/{user}/edit', EditUser::class)->name('users.edit');
});

// "Members only" = authenticated + verified email.
Route::middleware(['auth', 'verified'])->prefix('church/members')->group(function () {
    Route::get('', MemberController::class)->name('members.home');
});

Route::middleware(['auth', 'verified'])->prefix('church/songs')->name('church.songs.')->group(function () {
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

    Route::get('phpinfo', fn () => phpinfo())->middleware(['auth', 'verified', 'admin']);
}

// Catch-all dynamic page routes (these must be last!)
Route::get('/{area}', [PageController::class, 'showPage'])->where('area', '(?!_dusk)[^/]+')->name('pages.showArea');
Route::get('/{area}/{slug}', [PageController::class, 'show'])->where('area', '(?!_dusk)[^/]+')->name('pages.showPublic');
