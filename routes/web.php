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

// Sermon Routes

Route::group(array('prefix' => 'sermons'), function()
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

Route::resource('whats-on', 'MeetingController');

//Members

Auth::routes();

Route::group(['middleware' => 'auth', 'prefix' => 'members'], function()
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

Route::permanentRedirect('aboutus', 'about-us');
Route::permanentRedirect('contacttus', 'contact-us');
Route::permanentRedirect('links', 'links');
Route::permanentRedirect('whatson', 'whatson');
Route::permanentRedirect('where', 'find-us');

Route::permanentRedirect('aboutus/history', 'about-us/history');
Route::permanentRedirect('aboutus/pastor', 'about-us/pastor');
Route::permanentRedirect('aboutus/statementoffaith', 'about-us/statement-of-faith');
Route::permanentRedirect('aboutus/whatwebelieve', 'about-us/what-we-believe');

Route::permanentRedirect('whatson/1150', 'whats-on/1150');
Route::permanentRedirect('whatson/adventurers', 'whats-on/adventurers');
Route::permanentRedirect('whatson/babytalk', 'whats-on/baby-talk');
Route::permanentRedirect('whatson/biblestudy', 'whats-on/bible-study');
Route::permanentRedirect('whatson/buzzclub', 'whats-on/buzz-club');
Route::permanentRedirect('whatson/carolsatthechequers', 'whats-on/carols-at-the-chequers');
Route::permanentRedirect('whatson/christianityexplored', 'whats-on/christianity-explored');
Route::permanentRedirect('whatson/coffeecup', 'whats-on/coffee-cup');
Route::permanentRedirect('whatson/sunday', 'whats-on/sunday-services');

Route::permanentRedirect('buzz-club', 'whats-on/buzz-club');
Route::permanentRedirect('messy-church', 'whats-on/messy-church');

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
