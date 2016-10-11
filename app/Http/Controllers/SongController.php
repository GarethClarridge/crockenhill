<?php namespace Crockenhill\Http\Controllers;

use Crockenhill\Http\Requests;
use Crockenhill\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SongController extends Controller {

  /**
   * Display a listing of the resource.
   *
   * @return Response
   */
  public function index()
  {
    // Define slug and area to enable lookup of page in database
    $slug = 'songs';
    $area = 'members';

    // Look up page in pages table of database
    $page = \Crockenhill\Page::where('slug', $slug)->first();

    // Set values
    $heading = 'Songs';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li><li class="active">'.$page->heading.'</li>';

    // Load content
    $content = $page->body;

    // Load songs
    $songs =\Crockenhill\Song::all();

    // Books of the Bible
    $books = array(
              'Gen'   => 'Genesis',
              'Exod'  => 'Exodus',
              'Lev'   => 'Leviticus',
              'Num'   => 'Numbers',
              'Deut'  => 'Deuteronomy',
              'Josh'  => 'Joshua',
              'Judg'  => 'Judges',
              'Ruth'  => 'Ruth',
              '1Sam'  => '1 Samuel',
              '2Sam'  => '2 Samuel',
              '1Kgs'  => '1 Kings',
              '2Kgs'  => '2 Kings',
              '1Chr'  => '1 Chronicles',
              '2Chr'  => '2 Chronicles',
              'Ezr'   => 'Ezra',
              'Neh'   => 'Nehemiah',
              'Est'   => 'Esther',
              'Job'   => 'Job',
              'Ps'    => 'Psalms',
              'Prov'  => 'Proverbs',
              'Eccl'  => 'Ecclesiastes',
              'Song'  => 'Song of Songs',
              'Isa'   => 'Isaiah',
              'Jer'   => 'Jeremiah',
              'Lam'   => 'Lamentations',
              'Exek'  => 'Exekiel',
              'Dan'   => 'Daniel',
              'Hos'   => 'Hosea',
              'Joel'  => 'Joel',
              'Amos'  => 'Amos',
              'Obed'  => 'Obediah',
              'Jonah' => 'Jonah',
              'Mic'   => 'Micah',
              'Nah'   => 'Nahum',
              'Hab'   => 'Habbukuk',
              'Zeph'  => 'Zephaniah',
              'Hag'   => 'Haggai',
              'Zech'  => 'Zechariah',
              'Mal'   => 'Malachi',
              'Matt'  => 'Matthew',
              'Mark'  => 'Mark',
              'Luke'  => 'Luke',
              'John'  => 'John',
              'Acts'  => 'Acts',
              'Rom'   => 'Romans',
              '1Cor'  => '1 Corinthians',
              '2Cor'  => '2 Corinthians',
              'Gal'   => 'Galatians',
              'Eph'   => 'Ephesians',
              'Phil'  => 'Phillipians',
              'Col'   => 'Colossians',
              '1Thess'=> '1 Thessalonians',
              '2Thess'=> '2 Thessalonians',
              '1Tim'  => '1 Timothy',
              '2Tim'  => '2 Timothy',
              'Titus' => 'Titus',
              'Phm'   => 'Philemon',
              'Heb'   => 'Hebrews',
              'Jas'   => 'James',
              '1Pet'  => '1 Peter',
              '2Pet'  => '2 Peter',
              '1John' => '1 John',
              '2John' => '2 John',
              '3John' => '3 John',
              'Jude'  => 'Jude',
              'Rev'   => 'Revelation'
              );

    // Present page
    return view('songs.index', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => $content,
      'songs'       => $songs,
      'books'       => $books
    ));
  }

  public function showSong($id, $title)
  {
    // Look up song in songs table of database
    $song =\Crockenhill\Song::where('id', $id)->first();

    // Define slug to enable card logic to work
    $slug = $song->title;

    // Define area to enable lookup of related pages in database
    $area = 'members';

    // Find relevant links
    $links = \Crockenhill\Page::where('area', $area)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(\DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                    <li><a href="/members/songs">Songs</a></li>
                    <li class="active">'.$song->title.'</li>';
    if (is_null($song->alternative_title)) {
      $heading = $song->title;
    } else {
      $heading = $song->title.' - ('.$song->alternative_title.')';
    }

    // Present lyrics in a readable format
    $lyrics = nl2br(trim($song->lyrics));

    // Information about when last sung
    $last_played_record = \Crockenhill\PlayDate::where('song_id', $song->id)
                              ->orderBy('date', 'desc')
                              ->first();
    if ($last_played_record) {
      $last_played = $last_played_record->date;
    } else {
      $last_played = NULL;
    }

    // Information about how often we've sung it recently
    $years = 2;
    $frequency = \Crockenhill\PlayDate::where('song_id', $song->id)
                              ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
                              ->count();

    // Scripture References
    $scripture = \Crockenhill\ScriptureReference::where('song_id', $song->id)->get();

    // Graph information
    // Morning vs Evening Pie Chart Data
    $sung_morning = \Crockenhill\PlayDate::where('song_id', $song->id)
                              ->where('time', 'a')
                              ->count();
    $sung_evening = \Crockenhill\PlayDate::where('song_id', $song->id)
                              ->where('time', 'p')
                              ->count();

    // Popularity over time Line Graph Data
    $sung_year = [];
    $now = date('Y');
    while ($now > 2003) {
      $times = \Crockenhill\PlayDate::where('song_id', $song->id)
                          ->where('date', 'LIKE', $now.'%')
                          ->count();
      $sung_year[$now] = $times;
      $now--;
    }

    // Present page
    return view('songs.song', array(
      'song'        => $song,
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$song->title.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'links'       => $links,
      'content'     => '',
      'lyrics'      => $lyrics,
      'last_played' => $last_played,
      'frequency'   => $frequency,
      'years'       => $years,
      //'texts'       => $references,
      'scripture'   => $scripture,
      'sungmorning' => $sung_morning,
      'sungevening' => $sung_evening,
      'sungyear'    => array_reverse($sung_year, true),
      'year'        => date('Y')
    ));
  }

  public function postScriptureReferenceSearch()
  {
    // Get user's search
    $book     = \Input::get('book');
    $chapter  = \Input::get('chapter');
    $verse    = \Input::get('verse');

    // Send user to search results page
    return redirect('/members/songs/scripture-reference-search/'.$book.'.'.$chapter.'.'.$verse);
  }

  public function getScriptureReferenceSongs($reference) {
    // Define area to enable lookup of page in database
    $area = 'members';

    // Find relevant links
    $links = \Crockenhill\Page::where('area', $area)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(\DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $reference_array = explode('.', $reference);
    $formatted_reference = $reference_array[0].' '.$reference_array[1].':'.$reference_array[2];
    $heading = 'Songs for '.$formatted_reference;
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                    <li><a href="/members/songs">Songs</a></li>
                    <li><a href="/members/songs/scripture-reference">Scripture Reference</a></li>
                    <li class="active">'.$reference.'</li>';

    // Load songs
    // Get list of references
    $references = \Crockenhill\ScriptureReference::where('reference', $reference)->get();
    // Get song ids for each reference
    $ref = [];
    foreach ($references as $reference) {
      $ref[] = $reference->song_id;
    }
    // Get songs for each song id
    $songs =\Crockenhill\Song::whereIn('id', $ref)->get();

    // Present page
    return view('songs.scripture-reference-songs', array(
      'slug'        => $reference,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => '',
      'links'       => $links,
      'songs'       => $songs,
      'reference'   => $reference
    ));
  }

  public function postTextSearch()
  {
    // Get user's search
    $search = \Illuminate\Support\Str::slug(\Input::get('search'));

    // Send user to search results page
    return redirect('/members/songs/search/'.$search);
  }

  public function getTextSearchSongs($search) {
    // Define area to enable lookup of page in database
    $area = 'members';

    // Find relevant links
    $links = \Crockenhill\Page::where('area', $area)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(\DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Convert search back into a string
    $search = str_replace('-', ' ', $search);
    $words = str_replace(' ', '%', $search);

    // Set values
    $heading = 'Songs containing "'.$search.'"';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                    <li><a href="/members/songs">Songs</a></li>
                    <li class="active">Search: '.$search.'</li>';

    // Load songs
    $songs =\Crockenhill\Song::where('title', 'like', '%'.$words.'%')
                    ->orWhere('lyrics', 'like', '%'.$words.'%')
                    ->get();

    // Present page
    return view('songs.search-songs', array(
      'slug'        => \Illuminate\Support\Str::slug($search),
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => '',
      'links'       => $links,
      'songs'       => $songs,
      'search'      => $search
    ));
  }

  public function getServiceRecord()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    // Define slug and area to enable lookup of page in database
    $slug = 'service-record';
    $area = 'members';

    // Find relevant links
    $links = \Crockenhill\Page::where('area', $area)
      ->where('slug', '!=', $slug)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(\DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $heading = 'Upload New Service Record';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                      <li><a href="/members/songs">Songs</a></li>
                      <li class="active">'.$heading.'</li>';

    // Load content
    $content = '';

    // Services
    $services = array('a' => 'Morning', 'p' => 'Evening');

    // Get NIP titles
    $nips =\Crockenhill\Song::where('praise_number', '')
                  ->orWhere('praise_number', null)
                  ->select('title')
                  ->orderBy('title', 'asc')
                  ->get();

    $nips_titles = array();
    $nips_titles[''] = 'Please select ...';
    foreach ($nips as $nip) {
      $nips_titles[$nip->title] = $nip->title;
    }

    // Present page
    return view('songs.service-record', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => $content,
      'links'       => $links,
      'services'    => $services,
      'lastsunday'  => date('Y-m-d', strtotime('last Sunday')),
      'nips'        => $nips_titles,
    ));
  }

  public function postServiceRecord()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    $date = \Input::get('date');

    $service = array('a' => 'Morning', 'p' => 'Evening');

    foreach ($service as $key => $value) {
      $array = array();

      for ($i=1; $i < 10; $i++) {
        if (\Input::get($key.'m'.$i.'-number') !== '') {
          $array[] = \Input::get($key.'m'.$i.'-number');
        } elseif (\Input::get($key.'m'.$i.'-title') !== '') {
          $array[] = \Input::get($key.'m'.$i.'-title');
        }
      }

      foreach ($array as $value) {
        if ($value !== '') {
          if (\Crockenhill\Song::where('praise_number', $value)->first()) {
            $song =\Crockenhill\Song::where('praise_number', $value)->first();
          } elseif (\Crockenhill\Song::where('title', $value)->first()) {
            $song =\Crockenhill\Song::where('title', $value)->first();
          }

          $playdate = new \Crockenhill\PlayDate;
          $playdate->song_id = $song->id;
          $playdate->date = $date;
          $playdate->time = $key;
          $playdate->save();
        }
      }
    }

    // Send user back to index
    return redirect('/members/songs/');
  }

  public function create()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    // Define slug and area to enable lookup of page in database
    $slug = 'scripture-reference';
    $area = 'members';

    // Find relevant links
    $links = \Crockenhill\Page::where('area', $area)
      ->where('slug', '!=', $slug)
      ->where('slug', '!=', $area)
      ->where('slug', '!=', 'homepage')
      ->orderBy(\DB::raw('RAND()'))
      ->take(5)
      ->get();

    // Set values
    $heading = 'Add a new song to the list';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                      <li><a href="/members/songs">Songs</a></li>
                      <li class="active">'.$heading.'</li>';

    // Load content
    $content = '';

    // Present page
    return view('songs.create', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => $content,
      'links'       => $links,
    ));
  }

  public function store()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    // Get input
    $title        = \Input::get('title');
    $alternative  = \Input::get('alternative');
    $author       = \Input::get('author');
    $copyright    = \Input::get('copyright');
    $lyrics       = \Input::get('lyrics');

    // Save new song
    $song = new \Crockenhill\Song;
    $song->title              = $title;
    $song->alternative_title  = $alternative;
    $song->author             = $author;
    $song->copyright          = $copyright;
    $song->lyrics             = $lyrics;
    $song->save();

    // Send user back to index
    return redirect('/members/songs')->with('message', '"'.\Input::get('title').'" successfully uploaded!');
  }

}
