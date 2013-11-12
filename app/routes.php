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

// Level 1 Pages

Route::get('/', array('as' => 'Home', function()
{
    return View::make('pages.Home');
}));

Route::get('/aboutus', array('as' => 'AboutUs', function()
{
    return View::make('pages.AboutUs');
}));

Route::get('/whatson', array('as' => 'WhatsOn', function()
{
    return View::make('pages.WhatsOn');
}));

Route::get('/where', array('as' => 'Where', function()
{
    return View::make('pages.Where');
}));

Route::get('/sermons', array('as' => 'Sermons', 'uses' => 'SermonController@recent'));

Route::get('/publications', array('as' => 'Publications', function()
{
    return View::make('pages.Publications');
}));

Route::get('/contactus', array('as' => 'ContactUs', function()
{
    return View::make('pages.ContactUs');
}));

Route::get('/links', array('as' => 'Links', function()
{
    return View::make('pages.Links');
}));

// Level 2 Pages

// About Us Pages

Route::get('aboutus/whatwebelieve', array('as' => 'whatwebelieve', function()
{
    return View::make('pages.aboutus.whatwebelieve');
}));

Route::get('aboutus/statementoffaith', array('as' => 'statementoffaith', function()
{
    return View::make('pages.aboutus.statementoffaith');
}));

Route::get('aboutus/pastor', array('as' => 'pastor', function()
{
    return View::make('pages.aboutus.pastor');
}));

Route::get('aboutus/history', array('as' => 'history', function()
{
    return View::make('pages.aboutus.history');
}));

// What's On Pages

Route::get('whatson/sunday', array('as' => 'sunday', function()
{
    return View::make('pages.whatson.sunday');
}));

Route::get('whatson/biblestudy', array('as' => 'biblestudy', function()
{
    return View::make('pages.whatson.biblestudy');
}));

Route::get('whatson/babytalk', array('as' => 'babytalk', function()
{
    return View::make('pages.whatson.babytalk');
}));

Route::get('whatson/adventurers', array('as' => 'adventurers', function()
{
    return View::make('pages.whatson.adventurers');
}));

Route::get('whatson/1150', array('as' => '1150', function()
{
    return View::make('pages.whatson.1150');
}));

Route::get('whatson/link', array('as' => 'link', function()
{
    return View::make('pages.whatson.link');
}));

Route::get('whatson/menzone', array('as' => 'menzone', function()
{
    return View::make('pages.whatson.menzone');
}));

Route::get('whatson/coffeecup', array('as' => 'coffeecup', function()
{
    return View::make('pages.whatson.coffeecup');
}));

Route::get('whatson/cameo', array('as' => 'cameo', function()
{
    return View::make('pages.whatson.cameo');
}));

Route::get('whatson/walkinggroup', array('as' => 'walkinggroup', function()
{
    return View::make('pages.whatson.walkinggroup');
}));

Route::get('whatson/buzzclub', array('as' => 'buzzclub', function()
{
    return View::make('pages.whatson.buzzclub');
}));

Route::get('whatson/familyfunnight', array('as' => 'familyfunnight', function()
{
    return View::make('pages.whatson.familyfunnight');
}));

Route::get('whatson/carolsatthechequers', array('as' => 'carolsatthechequers', function()
{
    return View::make('pages.whatson.carolsatthechequers');
}));

Route::get('whatson/christianityexplored', array('as' => 'christianityexplored', function()
{
    return View::make('pages.whatson.christianityexplored');
}));

// Sermon pages

Route::get('sermons/{sermon}', array('as' => 'sermon', 'uses' => 'SermonController@current'));

Route::get('sermons/year/{year}', array('as' => 'sermonsbyyear', 'uses' => 'SermonController@year'));

Route::get('sermons/preacher/{preacher}', array('as' => 'sermonsbypreacher', 'uses' => 'SermonController@preacher'));

Route::get('sermons/series/{series}', array('as' => 'sermonsbyseries', 'uses' => 'SermonController@series'));

// Members stuff

Route::group(array('prefix' => '/membersarea', 'before' => 'auth'), function()
{

    Route::get('/', array('as' => 'Members', function()
    {
        return View::make('pages.Members');
    }));

    Route::get('/rotas', array('as' => 'rotas', function()
    {
        return View::make('pages.members.rotas');
    }));

    Route::get('/notes', array('as' => 'notes', function()
    {
        return View::make('pages.members.notes');
    }));

    Route::get('/documents', array('as' => 'documents', function()
    {
        return View::make('pages.members.documents');
    }));

    Route::get('/songs', array('as' => 'songs', function()
    {
        return View::make('pages.members.songs');
    }));

});

// Songs pages

Route::group(array('prefix' => '/membersarea/songs', 'before' => 'auth'), function()
{

    Route::get('/scripturesearch', array('as' => 'scripturesearch', 'uses' => 'SongController@scripturesearch'));

    Route::get('/keyword', array('as' => 'keyword', 'uses' => 'SongController@keywordsearch'));

    Route::get('/list', array('as' => 'songslist', 'uses' => 'SongController@index'));

    Route::get('/{song_id}', array('as' => 'song', 'uses' => 'SongController@song'));

});

Route::group(array('prefix' => '/membersarea', 'before' => 'guest'), function()
{

    Route::get('/login', array('as' => 'MembersLogin', function()
    {
        return View::make('pages.MembersLogin');
    }));

    Route::post('/login', array('uses' => 'AuthController@dologin'));

});

// Admin pages.

Route::group(array('prefix' => '/admin', 'before' => 'admin'), function()
{

    Route::get('/', array('as' => 'admin', function()
    {
        return View::make('pages.admin');
    }));
    
    Route::get('/login', array('as' => 'adminlogin', function()
    {
        return View::make('pages.admin.login');
    }));
    
});

// Short routes for linking to in print.

Route::get('pastor', function()
{
    return Redirect::route('pastor');
});

Route::get('abouts', function()
{
    return Redirect::route('AboutUs');
});

Route::get('aboutus', function()
{
    return Redirect::route('AboutUs');
});

Route::get('sunday', function()
{
    return Redirect::route('sunday');
});

Route::get('babytalk', function()
{
    return Redirect::route('babytalk');
});

Route::get('adventurers', function()
{
    return Redirect::route('adventurers');
});

Route::get('1150', function()
{
    return Redirect::route('1150');
});

Route::get('link', function()
{
    return Redirect::route('link');
});

Route::get('coffeecup', function()
{
    return Redirect::route('coffeecup');
});

Route::get('menzone', function()
{
    return Redirect::route('menzone');
});

Route::get('christianityexplored', function()
{
    return Redirect::route('christianityexplored');
});

Route::get('biblestudy', function()
{
    return Redirect::route('biblestudy');
});

// Other

App::missing(function($exception)
{
    return Response::view('errors.missing', array(), 404);
});
