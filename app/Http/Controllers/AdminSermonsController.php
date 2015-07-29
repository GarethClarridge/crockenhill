<?php namespace Crockenhill\Http\Controllers;

class AdminSermonsController extends BaseController {
 
    public function index()
    {
        $sermons = \Sermon::orderBy('date', 'desc')->get();
        return View::make('members.sermons.index')->with('sermons', $sermons);
    }

    public function create()
    {
        $series = array_unique(Sermon::lists('series'));

        return View::make('members.sermons.create')->with('series', $series);
    }

    public function store()
    {
        $file = Input::file('file');
        $filename = substr($file->getClientOriginalName(), 0, -4);
        if (substr($filename, -1) === 'b') {
            $service = 'evening';
        } else {
            $service = 'morning';
        }

        if (Input::get('series')){
            $series = Input::get('series');
        } else {
            $series = Input::get('new_series');
        }

        $sermon = new Sermon;
        $sermon->title      = Input::get('title');
        $sermon->filename   = $filename;
        $sermon->date       = Input::get('date');
        $sermon->service    = $service;
        $sermon->slug       = Str::slug(Input::get('title'));
        $sermon->series     = $series;
        $sermon->reference  = Input::get('reference');
        $sermon->preacher   = Input::get('preacher');
        $sermon->save();

        return Redirect::route('members.sermons.index');
    }

    public function edit($slug)
    {
        $sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
        $series = array_unique(Sermon::lists('series'));

        return View::make('members.sermons.edit')
            ->with('sermon', $sermon)
            ->with('series', $series);
    }

    public function update($slug)
    {
        if (Input::get('series')){
            $series = Input::get('series');
        } else {
            $series = Input::get('new_series');
        }

        $sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
        $sermon->title      = Input::get('title');
        $sermon->date       = Input::get('date');
        $sermon->slug       = Str::slug(Input::get('title'));
        $sermon->series     = $series;
        $sermon->reference  = Input::get('reference');
        $sermon->preacher   = Input::get('preacher');
        $sermon->save();

        Notification::success('The changes to the sermon were saved.');

        return Redirect::route('members.sermons.index');
            
    }

    public function destroy($slug)
    {
        $sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
        $sermon->delete();

        return Redirect::route('members.sermons.index');
    }

    public function changeimage($slug)
    {
        return View::make('members.sermons.editimage')->with('sermon', \Crockenhill\Sermon::where('slug', $slug)->first());
    }

    public function updateimage($slug)
    {
        $sermon = \Crockenhill\Sermon::where('slug', $slug)->first();

        // Make large image for article
        Image::make(Input::file('image')
            ->getRealPath())
            // resize the image to a width of 300 and constrain aspect ratio (auto height)
            ->resize(2000, null, true)
            ->save('images/headings/large/'.$sermon->slug.'.jpg');

        // Make smaller image for aside
        Image::make(Input::file('image')
            ->getRealPath())
            // resize the image to a width of 300 and constrain aspect ratio (auto height)
            ->resize(300, null, true)
            ->save('images/headings/small/'.$sermon->slug.'.jpg');

        Notification::success('The image was changed.');

        return Redirect::route('members.sermons.changeimage', array('sermon' => $sermon->slug));
            
    }

 
}
