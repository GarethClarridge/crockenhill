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

Route::get('members/logout', array(
    'as' => 'members.logout', 
    'uses' => 'AuthController@getLogout'
    ));
    
Route::get('members/login', array(
    'as' => 'members.login',
    'uses' => 'AuthController@getLogin'
    ));
    
Route::post('members/login', array(
    'as' => 'members.login.post', 
    'uses' => 'AuthController@postLogin'
    ));

Route::group(array('prefix' => 'members', 'before' => 'auth.members'), function()
{
        Route::any('/', 'MemberPageController@index');
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
});

Route::get('/{area}/{slug}', array('uses' => 'PageController@showSubPage'));

Route::get('/{slug}', array('uses' => 'PageController@showPage'));

