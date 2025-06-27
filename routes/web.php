<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// Import all necessary controllers
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\SermonController;
use App\Http\Controllers\RssFeedController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ServiceController;

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

Route::get('/', ['as' => 'Home', function () {
  return view('full-width-pages/home');
}]);

// Special pages route
Route::get('/christmas', ['as' => 'christmas', function () {
      return view('full-width-pages/christmas');
}]);
Route::get('/easter', ['as' => 'easter', function () {
      return view('full-width-pages/easter');
}]);
Route::get('/christianity-explored', ['as' => 'christianity-explored', function () {
      return view('full-width-pages/christianity-explored');
}]);

// Full width pages
Route::get('/christ', ['as' => 'christ', function () {
      return view('full-width-pages/christ');
}]);
Route::get('/church', ['as' => 'church', function () {
      return view('full-width-pages/church');
}]);

//Community routes
Route::resource('community', MeetingController::class);

// Sermon routes
Route::group(['prefix' => 'christ/sermons'], function () {
  Route::get('/', ['as' => 'sermonIndex', 'uses' => [SermonController::class, 'index']]);
  Route::get('/create', ['as' => 'sermonCreate', 'uses' => [SermonController::class, 'create']]);
  Route::post('/', ['as' => 'sermonStore', 'uses' => [SermonController::class, 'store']]);
  Route::get('/upload', ['as' => 'sermonUpload', 'uses' => [SermonController::class, 'upload']]);
  Route::post('/post', ['as' => 'sermonPost', 'uses' => [SermonController::class, 'post']]);
  Route::get('/{year}/{month}/{slug}', ['as' => 'showSermon', 'uses' => [SermonController::class, 'show']]);
  Route::get('/{year}/{month}/{slug}/edit', ['as' => 'editSermon', 'uses' => [SermonController::class, 'edit']]);
  Route::post('/{year}/{month}/{slug}/edit', ['as' => 'updateSermon', 'uses' => [SermonController::class, 'update']]);
  Route::post('/{year}/{month}/{slug}/delete', ['as' => 'destroySermon', 'uses' => [SermonController::class, 'destroy']]);
  Route::get('all', ['as' => 'allSermons', 'uses' => [SermonController::class, 'getAll']]);
  Route::get('preachers', ['as' => 'getPreachers', 'uses' => [SermonController::class, 'getPreachers']]);
  Route::get('preachers/{preacher}', ['as' => 'getPreacher', 'uses' => [SermonController::class, 'getPreacher']]);
  Route::get('series', ['as' => 'getSerieses', 'uses' => [SermonController::class, 'getSerieses']]);
  Route::get('series/{series}', ['as' => 'getSeries', 'uses' => [SermonController::class, 'getSeries']]);
  Route::get('{service}', ['as' => 'getService', 'uses' => [SermonController::class, 'getService']]);

  Route::get('evening/feed', [RssFeedController::class, 'eveningFeed']);
  Route::get('morning/feed', [RssFeedController::class, 'morningFeed']);
});

//Members routes
Auth::routes(); // This may need further review if laravel/ui is outdated.
Route::group(['middleware' => 'auth', 'prefix' => 'church/members'], function () {
  Route::get('', [MemberController::class, 'home']);
  // Manage pages
  Route::resource('pages', PageController::class);
  // Manage sermons
  Route::resource('sermons', SermonController::class);

  // Service recordings
  Route::resource('services', ServiceController::class);
});

Route::get('phpinfo', fn() => phpinfo());

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
Route::permanentRedirect('whats-on/carolsatthechequers', 'community/carols-at-the-chequers');
Route::permanentRedirect('whats-on/christianityexplored', 'community/christianity-explored');
Route::permanentRedirect('whats-on/coffeecup', 'community/coffee-cup');
Route::permanentRedirect('whats-on/sunday', 'community/sunday-mornings');

Route::permanentRedirect('buzz-club', 'community/buzz-club');
Route::permanentRedirect('messy-church', 'community/messy-church');
Route::permanentRedirect('reopening', 'attending-in-person');

Route::permanentRedirect('online', '/');
Route::permanentRedirect('resources', '/');

// General Routes
Route::get('/{area}/', [PageController::class, 'showPage']);
Route::get('/{area}/{slug}', [PageController::class, 'showPage']);

Route::get('500', function () {
  abort(500);
});
