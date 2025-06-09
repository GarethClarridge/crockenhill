<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

//Community routes - MOVED TO TOP FOR PRIORITY
// Route::resource('community', \Crockenhill\Http\Controllers\MeetingController::class); // Temporarily comment out
Route::get('/community', 'Crockenhill\Http\Controllers\MeetingController@index')->name('community.index');
Route::get('/community/create', 'Crockenhill\Http\Controllers\MeetingController@create')->name('community.create');
Route::post('/community', 'Crockenhill\Http\Controllers\MeetingController@store')->name('community.store');
Route::get('/community/{meeting}', 'Crockenhill\Http\Controllers\MeetingController@show')->name('community.show'); // Assuming slug is route key for {meeting}
Route::get('/community/{meeting}/edit', 'Crockenhill\Http\Controllers\MeetingController@edit')->name('community.edit');
Route::put('/community/{meeting}', 'Crockenhill\Http\Controllers\MeetingController@update')->name('community.update');
Route::delete('/community/{meeting}', 'Crockenhill\Http\Controllers\MeetingController@destroy')->name('community.destroy');

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

Route::view('/', 'full-width-pages/home')->name('Home');

// Special pages route
Route::view('/christmas', 'full-width-pages/christmas')->name('christmas');
Route::view('/easter', 'full-width-pages/easter')->name('easter');
Route::view('/christianity-explored', 'full-width-pages/christianity-explored')->name('christianity-explored');

// Full width pages
Route::view('/christ', 'full-width-pages/christ')->name('christ');
Route::view('/church', 'full-width-pages/church')->name('church');


// Sermon routes
Route::group(array('prefix' => 'christ/sermons'), function () {
  Route::get('/', array(
    'as' => 'sermonIndex',
    'uses' => 'SermonController@index'
  ));
  Route::get('/create', array(
    'as' => 'sermonCreate',
    'uses' => 'SermonController@create'
  ));
  Route::post('/', array(
    'as' => 'sermonStore',
    'uses' => 'SermonController@store'
  ));
  Route::get('/upload', array(
    'as' => 'sermonUpload',
    'uses' => 'SermonController@upload'
  ));
  Route::post('/post', array(
    'as' => 'sermonPost',
    'uses' => 'SermonController@post'
  ));
  Route::get('/{year}/{month}/{slug}', array(
    'as' => 'showSermon',
    'uses' => 'SermonController@show'
  ));
  Route::get('/{year}/{month}/{slug}/edit', array(
    'as' => 'editSermon',
    'uses' => 'SermonController@edit'
  ));
  Route::post('/{year}/{month}/{slug}/edit', array(
    'as' => 'updateSermon',
    'uses' => 'SermonController@update'
  ));
  Route::post('/{year}/{month}/{slug}/delete', array(
    'as' => 'destroySermon',
    'uses' => 'SermonController@destroy'
  ));
  Route::get('all', array(
    'as' => 'allSermons',
    'uses' => 'SermonController@getAll'
  ));
  Route::get('preachers', array(
    'as' => 'getPreachers',
    'uses' => 'SermonController@getPreachers'
  ));
  Route::get('preachers/{preacher}', array(
    'as' => 'getPreacher',
    'uses' => 'SermonController@getPreacher'
  ));
  Route::get('series', array(
    'as' => 'getSerieses',
    'uses' => 'SermonController@getSerieses'
  ));
  Route::get('series/{series}', array(
    'as' => 'getSeries',
    'uses' => 'SermonController@getSeries'
  ));
  Route::get('{service}', array(
    'as' => 'getService',
    'uses' => 'SermonController@getService'
  ));

  Route::get('evening/feed', 'RssFeedController@eveningFeed');
  Route::get('morning/feed', 'RssFeedController@morningFeed');
});

//Members routes
Auth::routes();
Route::group(['middleware' => 'auth', 'prefix' => 'church/members'], function () {
  Route::get('', [
    'uses' => 'MemberController@home'
  ]);
  // Manage pages
  Route::resource('pages', 'PageController');

  // Admin route for listing meetings
  Route::get('meetings', [\Crockenhill\Http\Controllers\MeetingController::class, 'listAllMeetings'])->name('meetings.admin_index');

  // Manage sermons
  Route::resource('sermons', 'SermonController');
  // Songs
  Route::get('songs/service-record', 'SongController@getServiceRecord');
  Route::post('songs/service-record', 'SongController@postServiceRecord');

  Route::get('songs/{id}/{title}', 'SongController@showSong');
  Route::get('songs/{id}/{title}/edit', 'SongController@editSong');
  Route::post('songs/{id}/{title}/edit', 'SongController@updateSong');

  Route::resource('songs', 'SongController');

  // Service recordings
  Route::resource('services', 'ServiceController');
});

// Route::get('phpinfo', fn() => phpinfo()); // Commented out for now to ensure cache compatibility

// Permanent Redirects
// - Very old website
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

// - Before restructure
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

// - Shortened URLs for advertising
Route::permanentRedirect('buzz-club', 'community/buzz-club');
Route::permanentRedirect('messy-church', 'community/messy-church');
Route::permanentRedirect('reopening', 'attending-in-person');

// - No more covid page
Route::permanentRedirect('online', '/');
Route::permanentRedirect('resources', '/');



// General Routes
// Route::get('/{area}/', array('uses' => 'PageController@showPage'));
// Route::get('/{area}/{slug}', array('uses' => 'PageController@showPage'));



// Route::get('500', function () { // Commented out for now
//   abort(500);
// });
