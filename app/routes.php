<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

Route::get('/', array('uses' => 'HomeController@showHome',
                        'as' => 'Home'));

// Sermon Routes

Route::group(array('prefix' => 'sermons'), function()
{

    Route::get('/', array(
        'as' => 'sermons',
        'uses' => 'SermonController@index'
    ));

    Route::get('preacher', array(
        'as' => 'preacherIndex',
        'uses' => 'SermonController@preacherIndex'
    ));

    Route::get('preacher/{preacher}', array(
        'as' => 'preacherShow',
        'uses' => 'SermonController@preacherShow'
    ));

    Route::get('series', array(
        'as' => 'seriesIndex',
        'uses' => 'SermonController@seriesIndex'
    ));

    Route::get('series/{series}', array(
        'as' => 'seriesShow',
        'uses' => 'SermonController@seriesShow'
    ));

    Route::get('/{slug}', array(
        'uses' => 'SermonController@show'
    ));

});

// Members Area Routes

// Confide routes

Route::get('members/login', 'MemberController@login');
Route::post('members/login', 'MemberController@doLogin');
Route::get('members/confirm/{code}', 'MemberController@confirm');
Route::get('members/forgot_password', 'MemberController@forgotPassword');
Route::post('members/forgot_password', 'MemberController@doForgotPassword');
Route::get('members/reset_password/{token}', 'MemberController@resetPassword');
Route::post('members/reset_password', 'MemberController@doResetPassword');
Route::get('members/logout', 'MemberController@logout');

Route::group(array('before' => 'auth.admin'), function()
{
    Route::get('members/create', 'MemberController@create');
    Route::post('users', 'MemberController@store');
});

// Custom Routes

Route::group(array('prefix' => 'members', 'before' => 'auth.member'), function()
{
    Route::get('/', function()
        {
            return Redirect::to('members/homepage');
        });

    Route::group(array('before' => 'auth.admin'), function()
    {
        Route::resource('sermons', 'AdminSermonsController');
        Route::resource('pages', 'AdminPagesController');
        Route::get('pages/{slug}/changeimage', array(
            'uses'  => 'AdminPagesController@changeimage',
            'as'    => 'members.pages.changeimage'
            ));
        Route::post('pages/{slug}/changeimage', array(
            'uses'  => 'AdminPagesController@updateimage',
            'as'    => 'members.pages.updateimage'
            ));
        Route::get('sermons/{slug}/changeimage', array(
            'uses'  => 'AdminSermonsController@changeimage',
            'as'    => 'members.sermons.changeimage'
            ));
        Route::post('sermons/{slug}/changeimage', array(
            'uses'  => 'AdminSermonsController@updateimage',
            'as'    => 'members.sermons.updateimage'
            ));
    });

    Route::controller('songs', 'SongController');

    Route::get('/{slug}', array('uses' => 'PageController@showMemberPage'));
});

// Permanent Redirects

Route::get('aboutus', function(){
    return Redirect::to('about-us', 301);
});
Route::get('abouts', function(){
    return Redirect::to('about-us', 301);
});
Route::get('contactus', function(){
    return Redirect::to('contact-us', 301);
});
Route::get('links', function(){
    return Redirect::to('about-us/links', 301);
});
Route::get('whatson', function(){
    return Redirect::to('whats-on', 301);
});
Route::get('where', function(){
    return Redirect::to('find-us', 301);
});
Route::get('aboutus/history', function(){
    return Redirect::to('about-us/history', 301);
});
Route::get('aboutus/pastor', function(){
    return Redirect::to('about-us/pastor', 301);
});
Route::get('aboutus/statementoffaith', function(){
    return Redirect::to('about-us/statement-of-faith', 301);
});
Route::get('aboutus/whatwebelieve', function(){
    return Redirect::to('about-us/what-we-believe', 301);
});
Route::get('whatson/1150', function(){
    return Redirect::to('whats-on/1150', 301);
});
Route::get('whatson/adventurers', function(){
    return Redirect::to('whats-on/adventurers', 301);
});
Route::get('whatson/babytalk', function(){
    return Redirect::to('whats-on/baby-talk', 301);
});
Route::get('whatson/biblestudy', function(){
    return Redirect::to('whats-on/bible-study', 301);
});
Route::get('whatson/buzzclub', function(){
    return Redirect::to('whats-on/buzz-club', 301);
});
Route::get('whatson/carolsatthechequers', function(){
    return Redirect::to('whats-on/carols-in-the-chequers', 301);
});
Route::get('whatson/christianityexplored', function(){
    return Redirect::to('whats-on/christianity-explored', 301);
});
Route::get('whatson/coffeecup', function(){
    return Redirect::to('whats-on/coffee-cup', 301);
});
Route::get('whatson/familyfunnight', function(){
    return Redirect::to('whats-on/family-fun-night', 301);
});
Route::get('whatson/link', function(){
    return Redirect::to('whats-on/link', 301);
});
Route::get('whatson/menzone', function(){
    return Redirect::to('whats-on/menzone', 301);
});
Route::get('whatson/sunday', function(){
    return Redirect::to('whats-on/sunday-services', 301);
});
Route::get('whatson/walkinggroup', function(){
    return Redirect::to('whats-on/walking-group', 301);
});
Route::get('publications', function(){
    return App::abort(404);
});

// General Routes

Route::get('/{area}/{slug}', array('uses' => 'PageController@showSubPage'));

Route::get('/{slug}', array('uses' => 'PageController@showPage'));

//Error pages

App::missing(function($exception)
{
    return Response::view('errors.missing', array(), 404);
});