<?php

/*
|--------------------------------------------------------------------------
| Application & Route Filters
|--------------------------------------------------------------------------
|
| Below you will find the "before" and "after" events for the application
| which may be used to do any work before or after a request into your
| application. Here you may also register your custom route filters.
|
*/

App::before(function($request)
{
	//
});


App::after(function($request, $response)
{
	//
});

/*
|--------------------------------------------------------------------------
| Authentication Filters
|--------------------------------------------------------------------------
|
| The following filters are used to verify that the user of the current
| session is logged into this application. The "basic" filter easily
| integrates HTTP Basic authentication for quick, simple checking.
|
*/

Route::filter('auth', function()
{
	if (Auth::guest()) return Redirect::guest('login');
});


Route::filter('auth.basic', function()
{
	return Auth::basic();
});

Route::filter('auth.member', function()
{
    if (! Entrust::hasRole('Member') && ! Entrust::hasRole('Admin') ) // Checks the current user
    {
        return Redirect::to('/members/login');
    }
});

Route::filter('auth.admin', function()
{
    if (! Entrust::hasRole('Admin') ) // Checks the current user
    {
        return Redirect::to('/members/login');
    }
});

/*
|--------------------------------------------------------------------------
| Guest Filter
|--------------------------------------------------------------------------
|
| The "guest" filter is the counterpart of the authentication filters as
| it simply checks that the current user is not logged in. A redirect
| response will be issued if they are, which you may freely change.
|
*/

Route::filter('guest', function()
{
	if (Auth::check()) return Redirect::to('/');
});

/*
|--------------------------------------------------------------------------
| CSRF Protection Filter
|--------------------------------------------------------------------------
|
| The CSRF filter is responsible for protecting your application against
| cross-site request forgery attacks. If this special token in a user
| session does not match the one given in this request, we'll bail.
|
*/

Route::filter('csrf', function()
{
	if (Session::token() != Input::get('_token'))
	{
		throw new Illuminate\Session\TokenMismatchException;
	}
});

// A file I've made to house the view composers, 
// as I couldn't think of a better place for them.

View::composer('includes.header', function($view)
    {
        $pages = array(

            'AboutUs' => array('route'=> 'about-us', 'name' => 'About Us'),
            'WhatsOn' => array('route'=> 'whats-on', 'name' => 'What\'s On'),
            'FindUs' => array('route'=> 'find-us', 'name' => 'Find Us'),
            'ContactUs' => array('route' => 'contact-us', 'name' => 'Contact Us'),
            'Sermons' =>array('route'=> 'sermons', 'name' => 'Sermons'),
            'Members' =>array('route'=> 'members', 'name' => 'Members'),
        
        );
        
        $view->with('pages', $pages);
        
    });
    
View::composer('includes.footer', function($view)
    {
        //get the latest sermons
        $morning = Sermon::where('service', 'morning')
            ->orderBy('date', 'desc')->first();
        $evening = Sermon::where('service', 'evening')
            ->orderBy('date', 'desc')->first();

        // and create the view composer
        $view->with('morning', $morning);
        $view->with('evening', $evening);
    });

View::composer('layouts.members', function($view)
    {
        $area = 'members';
        $links = Page::where('area', $area)->orderBy(DB::raw('RAND()'))->take(5)->get();

        $view->with('links', $links);
    });
