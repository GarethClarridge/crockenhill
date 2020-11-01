<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::group(['middleware' => ['web']], function() {

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

    Route::resource('community', 'MeetingController');

    // Custom Routes

    //Members

    Route::auth();

    Route::group(['prefix' => 'members', 'middleware' => 'auth'], function()
    {
        Route::get('', [
            'uses' => 'PageController@showPage'
        ]);

        // Manage pages
        Route::resource('pages', 'PageController');

        Route::get('pages/{slug}/changeimage', array(
            'uses'  => 'PageController@changeimage',
            'as'    => 'members.pages.changeimage'
            ));
        Route::post('pages/{slug}/changeimage', array(
            'uses'  => 'PageController@updateimage',
            'as'    => 'members.pages.updateimage'
            ));

        // Manage sermons
        Route::resource('sermons', 'AdminSermonsController');
        Route::get('sermons/{slug}/changeimage', array(
            'uses'  => 'AdminSermonsController@changeimage',
            'as'    => 'members.sermons.changeimage'
            ));
        Route::post('sermons/{slug}/changeimage', array(
            'uses'  => 'AdminSermonsController@updateimage',
            'as'    => 'members.sermons.updateimage'
            ));

        // Manage documents
        Route::resource('document', 'DocumentController');
        Route::get('documents', array(
            'uses'          => 'DocumentController@index'
            ));

        // Songs

        Route::post('songs/scripture-reference-search', 'SongController@postScriptureReferenceSearch');
        Route::get('songs/scripture-reference-search/{reference}', 'SongController@getScriptureReferenceSongs');

        Route::post('songs/search', 'SongController@postTextSearch');
        Route::get('songs/search/{search}', 'SongController@getTextSearchSongs');

        Route::get('songs/service-record', 'SongController@getServiceRecord');
        Route::post('songs/service-record', 'SongController@postServiceRecord');

        Route::get('songs/{id}/{title}', 'SongController@showSong');

        Route::resource('songs', 'SongController');
    });

    // Permanent Redirects

    Route::get('aboutus', function(){
        return Redirect::to('church', 301);
    });
    Route::get('abouts', function(){
        return Redirect::to('church', 301);
    });
    Route::get('contactus', function(){
        return Redirect::to('contact-us', 301);
    });
    Route::get('links', function(){
        return Redirect::to('church/links', 301);
    });
    Route::get('community', function(){
        return Redirect::to('community', 301);
    });
    Route::get('where', function(){
        return Redirect::to('find-us', 301);
    });
    Route::get('aboutus/history', function(){
        return Redirect::to('church/history', 301);
    });
    Route::get('aboutus/pastor', function(){
        return Redirect::to('church/pastor', 301);
    });
    Route::get('aboutus/statementoffaith', function(){
        return Redirect::to('church/statement-of-faith', 301);
    });
    Route::get('aboutus/whatwebelieve', function(){
        return Redirect::to('church/what-we-believe', 301);
    });
    Route::get('community/1150', function(){
        return Redirect::to('community/1150', 301);
    });
    Route::get('community/adventurers', function(){
        return Redirect::to('community/adventurers', 301);
    });
    Route::get('community/babytalk', function(){
        return Redirect::to('community/baby-talk', 301);
    });
    Route::get('community/biblestudy', function(){
        return Redirect::to('community/bible-study', 301);
    });
    Route::get('community/buzzclub', function(){
        return Redirect::to('community/buzz-club', 301);
    });
    Route::get('community/carolsatthechequers', function(){
        return Redirect::to('community/carols-in-the-chequers', 301);
    });
    Route::get('community/christianityexplored', function(){
        return Redirect::to('community/christianity-explored', 301);
    });
    Route::get('community/coffeecup', function(){
        return Redirect::to('community/coffee-cup', 301);
    });
    Route::get('community/familyfunnight', function(){
        return Redirect::to('community/family-fun-night', 301);
    });
    Route::get('community/link', function(){
        return Redirect::to('community/link', 301);
    });
    Route::get('community/menzone', function(){
        return Redirect::to('community/menzone', 301);
    });
    Route::get('community/sunday', function(){
        return Redirect::to('community/sunday-services', 301);
    });
    Route::get('community/walkinggroup', function(){
        return Redirect::to('community/walking-group', 301);
    });
    Route::get('publications', function(){
        return \App::abort(404);
    });

    // General Routes

    Route::get('/{area}/{slug?}', array('uses' => 'PageController@showPage'));

    Route::controllers([
    	'auth' => 'Auth\AuthController',
    	'password' => 'Auth\PasswordController',
    ]);

    Route::get('/', ['as' => 'Home', function()
    {
        return view('home');
    }]);

});
