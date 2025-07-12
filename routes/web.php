<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
// Import all necessary controllers
use App\Http\Controllers\MeetingController; // Added this line
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SermonController;
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
Route::view('/christianity-explored', 'full-width-pages.christianity-explored')->name('christianity-explored');

// Full width pages
Route::view('/christ', 'full-width-pages.christ')->name('christ');
Route::view('/church', 'full-width-pages.church')->name('church');
Route::view('/community', 'full-width-pages.community')->name('community');

// Meeting routes
Route::resource('meetings', MeetingController::class);

// Community meeting or page route (must be above catch-alls)
Route::get('/community/{slug}', [MeetingController::class, 'showCommunityContent'])->name('community.meeting-or-page');

// Sermon routes
Route::group(['prefix' => 'christ/sermons'], function () {
    Route::get('/', [SermonController::class, 'index'])->name('sermonIndex');
    Route::get('/create', [SermonController::class, 'create'])->name('sermonCreate');
    Route::post('/', [SermonController::class, 'store'])->name('sermonStore');
    Route::get('/upload', [SermonController::class, 'upload'])->name('sermonUpload');
    Route::post('/post', [SermonController::class, 'post'])->name('sermonPost');
    Route::get('all', [SermonController::class, 'getAll'])->name('allSermons');
    Route::get('preachers', [SermonController::class, 'getPreachers'])->name('getPreachers');
    Route::get('preachers/{preacher}', [SermonController::class, 'getPreacher'])->name('getPreacher');
    Route::get('series', [SermonController::class, 'getSerieses'])->name('getSerieses');
    Route::get('series/{series}', [SermonController::class, 'getSeries'])->name('getSeries');
    Route::get('{service}', [SermonController::class, 'getService'])->where('service', 'morning|evening|other')->name('getService');
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

Route::group(['middleware' => 'auth', 'prefix' => 'church/members'], function () {
    Route::get('', MemberController::class)->name('memberHome'); // Changed for invokable controller
    // Manage pages
    Route::resource('pages', PageController::class);
    // Manage sermons
    Route::resource('sermons', SermonController::class);
    // Manage meetings
    Route::resource('meetings', MeetingController::class);
});

Route::get('phpinfo', fn () => phpinfo())->middleware('admin');

// Permanent Redirects (unchanged)
Route::permanentRedirect('aboutus', 'church');
Route::permanentRedirect('contacttus', '/');
Route::permanentRedirect('links', 'church/links');
Route::permanentRedirect('whatson', 'community');
Route::permanentRedirect('whats-on', 'community');
Route::permanentRedirect('where', 'church/find-us');
Route::permanentRedirect('aboutus/history', 'church/history');
Route::permanentRedirect('aboutus/pastor', 'church/pastor');
Route::permanentRedirect('aboutus/statementoffaith', 'church/statement-of-faith');
Route::permanentRedirect('aboutus/whatwebelieve', 'church/what-we-believe');
Route::permanentRedirect('whatson/1150', 'community/1150');
Route::permanentRedirect('whatson/adventurers', 'community/adventurers');
Route::permanentRedirect('whatson/babytalk', 'community/baby-talk');
Route::permanentRedirect('whatson/biblestudy', 'community/bible-study');
Route::permanentRedirect('whatson/buzzclub', 'community/buzz-club');
Route::permanentRedirect('whatson/carolsatthechequers', 'community/carols-at-the-chequers');
Route::permanentRedirect('whatson/christianityexplored', 'community/christianity-explored');
Route::permanentRedirect('whatson/coffeecup', 'community/coffee-cup');
Route::permanentRedirect('whatson/sunday', 'community/sunday-mornings');

Route::permanentRedirect('about-us', 'church');
Route::permanentRedirect('about-us/history', 'church/history');
Route::permanentRedirect('about-us/pastor', 'church/pastor');
Route::permanentRedirect('about-us/links', 'church/links');
Route::permanentRedirect('about-us/statementoffaith', 'church/statement-of-faith');
Route::permanentRedirect('about-us/whatwebelieve', 'church/what-we-believe');
Route::permanentRedirect('about-us/privacy-policy', 'church/privacy-policy');
Route::permanentRedirect('about-us/safeguarding-policy', 'church/safeguarding-policy');
Route::permanentRedirect('whats-on/1150', 'community/1150');
Route::permanentRedirect('whats-on/adventurers', 'community/adventurers');
Route::permanentRedirect('whats-on/babytalk', 'community/baby-talk');
Route::permanentRedirect('whats-on/biblestudy', 'community/bible-study');
Route::permanentRedirect('whats-on/buzzclub', 'community/buzz-club');
Route::permanentRedirect('whats-on/buzz-club', 'community/buzz-club');
Route::permanentRedirect('whats-on/carolsatthechequers', 'community/carols-at-the-chequers');
Route::permanentRedirect('whats-on/christianityexplored', 'community/christianity-explored');
Route::permanentRedirect('whats-on/coffeecup', 'community/coffee-cup');
Route::permanentRedirect('whats-on/sunday', 'community/sunday-mornings');

Route::permanentRedirect('buzz-club', 'community/buzz-club');
Route::permanentRedirect('messy-church', 'community/messy-church');
Route::permanentRedirect('reopening', 'attending-in-person');

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
