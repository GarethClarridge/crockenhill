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

  public function showSong($id, $title)
  {
    // Look up song in songs table of database
    $song = Song::where('id', $id)->first();

    // Define slug to enable card logic to work
    $slug = $song->title;

    // Define area to enable lookup of related pages in database
    $area = 'members';

    // Find relevant links
    $links = Page::where('area', $area)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                    <li><a href="/members/songs">Songs</a></li>
                    <li class="active">'.$song->title.'</li>';

    $lyrics = nl2br(trim($song->lyrics));

    $last_played = PlayDate::where('song_id', $song->id)
                              ->orderBy('date', 'desc')
                              ->first()
                              ->date;

    $years = 2;

    $frequency = PlayDate::where('song_id', $song->id)
                              ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
                              ->count();

    $scripture = ScriptureReference::where('song_id', $song->id)->get();
      
    $this->layout->content = View::make('pages.songs.song', array(
        'song'        => $song,
        'slug'        => $slug,
        'heading'     => $song->title,     
        'description' => '<meta name="description" content="Songs sung at Crockenhill Baptist Church.">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'links'       => $links,
        'content'     => '',
        'lyrics'      => $lyrics,
        'last_played' => $last_played,
        'frequency'   => $frequency,
        'years'       => $years,
        'scripture'   => $scripture
    ));
  }

  public function getScriptureReference() {
    // Define slug and area to enable lookup of page in database
    $slug = 'scripture-reference';
    $area = 'members';

    // Find relevant links
    $links = Page::where('area', $area)
      ->where('slug', '!=', $slug)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $heading = 'Search Scripture References';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                      <li><a href="/members/songs">Songs</a></li>
                      <li class="active">'.$heading.'</li>';
    
    // Load content
    $content = '';
      
    $this->layout->content = View::make('pages.songs.scripture-reference', array(
        'slug'        => $slug,
        'heading'     => $heading,        
        'description' => '<meta name="description" content="Songs sung at Crockenhill Baptist Church.">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'content'     => $content,
        'links'       => $links,
    ));
  }

  public function postScriptureReference() {

    $book     = Input::get('book');
    $chapter  = Input::get('chapter');
    $verse    = Input::get('verse');

    return Redirect::to('/members/songs/scripture-reference/'.$book.'.'.$chapter.'.'.$verse);
  }

  public function getReferenceSongs($reference) {
    // Define area to enable lookup of page in database
    $area = 'members';

    // Find relevant links
    $links = Page::where('area', $area)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $heading = 'Songs for '.$reference;
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                      <li><a href="/members/songs">Songs</a></li>
                      <li><a href="/members/songs/scripture-reference">Scripture Reference</a></li>
                      <li class="active">'.$reference.'</li>';    

    // Load songs
    $references = ScriptureReference::where('reference', $reference)->get();

    $ref = [];

    foreach ($references as $reference) {
      $ref[] = $reference->song_id;
    }

    $songs = Song::whereIn('id', $ref)->get();
      
    $this->layout->content = View::make('pages.songs.scripture-reference-songs', array(
        'slug'        => $reference,
        'heading'     => $heading,        
        'description' => '<meta name="description" content="Songs sung at Crockenhill Baptist Church.">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'content'     => '',
        'links'       => $links,
        'songs'       => $songs,
        'reference'   => $reference
    ));
  }


}