<?php 

class AdminPagesController extends BaseController {
 
    public function index()
    {
        return View::make('members.pages.index')->with('pages', Page::all());
    }

    public function show($slug)
    {
        return View::make('members.pages.show')->with('page', Page::where('slug', $slug)->first());
    }

    public function create()
    {
        return View::make('members.pages.create');
    }

    public function store()
    {
        $page = new Page;
        $page->heading = Input::get('heading');
        $page->slug = Str::slug(Input::get('heading'));
        $page->body = Input::get('body');
        $page->description = Input::get('description');
        $page->save();

        return Redirect::route('members.pages.edit', $page->slug);
    }

    public function edit($slug)
    {
            return View::make('members.pages.edit')->with('page', Page::where('slug', $slug)->first());
    }

    public function update($slug)
    {
        $page = Page::where('slug', $slug)->first();
        $page->heading = Input::get('heading');
        $page->slug = Str::slug(Input::get('heading'));
        $page->body = Input::get('body');
        $page->description = Input::get('description');
        $page->save();

        Notification::success('The page was saved.');

        return Redirect::route('members.pages.edit', $page->slug);
            
    }

    public function destroy($slug)
    {
        $page = Page::where('slug', $slug)->first();
        $page->delete();

        return Redirect::route('members.pages.index');
    }
 
}
