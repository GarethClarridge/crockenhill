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

    foreach ($songs as $song) {
      $last_played_record = \Crockenhill\PlayDate::where('song_id', $song->id)
                                ->orderBy('date', 'desc')
                                ->first();
      if ($last_played_record) {
        $last_played = $last_played_record->date;
      } else {
        $last_played = NULL;
      }
      $song['last_played'] = $last_played;

      // Information about how often we've sung it recently
      $years = 2;
      $frequency = \Crockenhill\PlayDate::where('song_id', $song->id)
                                ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
                                ->count();
      if ($frequency >= 1) {
        $song['frequency'] = $frequency;
      } else {
        $song['frequency'] = 0;
      }
    }

    // Present page
    return view('songs.index', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => $content,
      'songs'       => $songs->sortByDesc('frequency')
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

    // // Information about when last sung
    // $last_played_record = \Crockenhill\PlayDate::where('song_id', $song->id)
    //                           ->orderBy('date', 'desc')
    //                           ->first();
    // if ($last_played_record) {
    //   $last_played = $last_played_record->date;
    // } else {
    //   $last_played = NULL;
    // }
    //
    // // Information about how often we've sung it recently
    // $years = 2;
    // $frequency = \Crockenhill\PlayDate::where('song_id', $song->id)
    //                           ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
    //                           ->count();

    $last_played_record = \Crockenhill\PlayDate::where('song_id', $song->id)
                              ->orderBy('date', 'desc')
                              ->first();
    if ($last_played_record) {
      $last_played = $last_played_record->date;
    } else {
      $last_played = NULL;
    }
    $song['last_played'] = $last_played;

    // Information about how often we've sung it recently
    $years = 2;
    $frequency = \Crockenhill\PlayDate::where('song_id', $song->id)
                              ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
                              ->count();
    if ($frequency >= 1) {
      $song['frequency'] = $frequency;
    } else {
      $song['frequency'] = 0;
    }

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
    $slug = 'create-song';
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

  public function editSong($id, $title)
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    // Look up song in songs table of database
    $song =\Crockenhill\Song::where('id', $id)->first();

    // Define slug and area to enable lookup of page in database
    $slug = 'edit-song';
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
    $heading = 'Edit '.$song->title;
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li>
                      <li><a href="/members/songs">Songs</a></li>
                      <li class="active">'.$heading.'</li>';

    // Load content
    $content = '';

    // Present page
    return view('songs.edit', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="'.$heading.'">',
      'area'        => $area,
      'breadcrumbs' => $breadcrumbs,
      'content'     => $content,
      'links'       => $links,
      'song'        => $song
    ));
  }

  public function updateSong($id, $title)
	{
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    // Look up song in songs table of database
    $song =\Crockenhill\Song::where('id', $id)->first();

    $song->title      = \Input::get('title');
    $song->alternative_title       = \Input::get('alternative_title');
    $song->major_category     = \Input::get('major_category');
    $song->minor_category  = \Input::get('minor_category');
    $song->author   = \Input::get('author');
    $song->copyright   = \Input::get('copyright');
    $song->lyrics   = \Input::get('lyrics');
    $song->current   = \Input::get('current');
    $song->save();

    // Send user back to index
    return redirect('/members/songs')->with('message', '"'.\Input::get('title').'" successfully updated '.$song->title.'!');
	}

}
