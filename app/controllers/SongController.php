<?php

class SongController extends BaseController {
  
  protected $layout = 'layouts.main';

  public function getIndex()
  {
    // Define slug and area to enable lookup of page in database
    $slug = 'songs';
    $area = 'members';

    // Look up page in pages table of database
    $page = Page::where('slug', $slug)->first();

    // Find relevant links
    $links = Page::where('area', $area)
      ->where('slug', '!=', $slug)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $heading = 'Songs';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li><li class="active">'.$page->heading.'</li>';
    
    // Load content
    $content = $page->body;

    // Load songs
    $songs = Song::all();
      
    $this->layout->content = View::make('pages.songs.index', array(
        'slug'        => $slug,
        'heading'     => $heading,        
        'description' => '<meta name="description" content="Songs sung at Crockenhill Baptist Church.">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'content'     => $content,
        'links'       => $links,
        'songs'       => $songs
    ));
  }

  public function showSong($id)
  {
    // Define area to enable lookup of related pages in database
    $area = 'members';

    // Look up song in songs table of database
    $song = Song::where('id', $id)->first();

    // Define slug to enable card logic to work
    $slug = $song->title;

    // Find relevant links
    $links = Page::where('area', $area)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $heading = $song->title;
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                    <li><a href="/members/songs">Songs</a></li>
                    <li class="active">'.$song->title.'</li>';
    
    // Load songs
    $songs = Song::all();
      
    $this->layout->content = View::make('pages.songs.song', array(
        'song'        => $song,
        'slug'        => $slug,
        'heading'     => $heading,        
        'description' => '<meta name="description" content="Songs sung at Crockenhill Baptist Church.">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'links'       => $links,
        'content'     => ''
    ));
  }


}