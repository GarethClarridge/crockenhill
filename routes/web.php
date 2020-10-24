<?php

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

// Christmas route

Route::get('/christmas', array(
  'as' => 'christmas', function()
  {
    return view('christmas');
  })
);

Route::get('/easter', array(
  'as' => 'easter', function()
  {
    return view('easter');
  })
);

Route::get('/reopening', array(
  'as' => 'reopening', function()
  {
    return view('reopening');
  })
);

Route::get('/online', array(
  'as' => 'online', function()
  {
    return view('online');
  })
);


Route::get('/resources', array(
  'as' => 'resources', function()
  {
    return view('resources');
  })
);

// Sermon Routes

Route::group(array('prefix' => 'church/sermons'), function()
{
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
});

Route::resource('community', 'MeetingController');

//Members
Auth::routes();

Route::group(['middleware' => 'auth', 'prefix' => 'church/members'], function()
{
    Route::get('', [
        'uses' => 'MemberController@home'
    ]);

    // Manage pages
    Route::resource('pages', 'PageController');

    // Manage sermons
    Route::resource('sermons', 'SermonController');

    // Manage documents
    Route::resource('documents', 'DocumentController');
    Route::get('documents', array(
        'uses'          => 'DocumentController@index'
        ));

    // Songs
    Route::get('songs/service-record', 'SongController@getServiceRecord');
    Route::post('songs/service-record', 'SongController@postServiceRecord');

    Route::get('songs/{id}/{title}', 'SongController@showSong');
    Route::get('songs/{id}/{title}/edit', 'SongController@editSong');
    Route::post('songs/{id}/{title}/edit', 'SongController@updateSong');

    Route::resource('songs', 'SongController');
});

// Permanent Redirects

Route::permanentRedirect('aboutus', 'church');
Route::permanentRedirect('about-us', 'church');
Route::permanentRedirect('contacttus', '/');
Route::permanentRedirect('links', 'church/links');
Route::permanentRedirect('whatson', 'community');
Route::permanentRedirect('whats-on', 'community');
Route::permanentRedirect('where', 'church/find-us');

Route::permanentRedirect('aboutus/history', 'church/history');
Route::permanentRedirect('aboutus/pastor', 'church/pastor');
Route::permanentRedirect('aboutus/statementoffaith', 'church/statement-of-faith');
Route::permanentRedirect('aboutus/whatwebelieve', 'church/what-we-believe');

Route::permanentRedirect('about-us/history', 'church/history');
Route::permanentRedirect('about-us/pastor', 'church/pastor');
Route::permanentRedirect('about-us/links', 'church/links');
Route::permanentRedirect('about-us/statementoffaith', 'church/statement-of-faith');
Route::permanentRedirect('about-us/whatwebelieve', 'church/what-we-believe');
Route::permanentRedirect('about-us/privacy-policy', 'church/privacy-policy');
Route::permanentRedirect('about-us/safeguarding-policy', 'church/safeguarding-policy');

Route::permanentRedirect('whatson/1150', 'community/1150');
Route::permanentRedirect('whatson/adventurers', 'community/adventurers');
Route::permanentRedirect('whatson/babytalk', 'community/baby-talk');
Route::permanentRedirect('whatson/biblestudy', 'community/bible-study');
Route::permanentRedirect('whatson/buzzclub', 'community/buzz-club');
Route::permanentRedirect('whatson/carolsatthechequers', 'community/carols-at-the-chequers');
Route::permanentRedirect('whatson/christianityexplored', 'community/christianity-explored');
Route::permanentRedirect('whatson/coffeecup', 'community/coffee-cup');
Route::permanentRedirect('whatson/sunday', 'community/sunday-services');

Route::permanentRedirect('whats-on/1150', 'community/1150');
Route::permanentRedirect('whats-on/adventurers', 'community/adventurers');
Route::permanentRedirect('whats-on/babytalk', 'community/baby-talk');
Route::permanentRedirect('whats-on/biblestudy', 'community/bible-study');
Route::permanentRedirect('whats-on/buzzclub', 'community/buzz-club');
Route::permanentRedirect('whats-on/carolsatthechequers', 'community/carols-at-the-chequers');
Route::permanentRedirect('whats-on/christianityexplored', 'community/christianity-explored');
Route::permanentRedirect('whats-on/coffeecup', 'community/coffee-cup');
Route::permanentRedirect('whats-on/sunday', 'community/sunday-services');

Route::permanentRedirect('buzz-club', 'community/buzz-club');
Route::permanentRedirect('messy-church', 'community/messy-church');

// General Routes

Route::get('/{area}/{slug?}', array('uses' => 'PageController@showPage'));

Route::get('/', ['as' => 'Home', function()
{
    return view('home');
}]);

Route::get('500', function()
{
    abort(500);
});
