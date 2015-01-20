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

    Route::get('/{slug}', array('uses' => 'PageController@showMemberPage'));
});

// General Routes

Route::get('/{area}/{slug}', array('uses' => 'PageController@showSubPage'));

Route::get('/{slug}', array('uses' => 'PageController@showPage'));