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

      if ($song->praise_number == NULL) {
        $song['nip'] = 'nip';
      } else {
        $song['nip'] = 'praise';
      }
    }

    $last_service_uploaded = \Crockenhill\PlayDate::orderBy('date', 'desc')->first(['date']);

    // Present page
    return view('songs.index', array(
      'songs'       => $songs->sortByDesc('frequency'),
      'last_service_uploaded' => $last_service_uploaded
    ));
  }

  public function showSong($id, $title)
  {
    // Look up song in songs table of database
    $song =\Crockenhill\Song::where('id', $id)->first();

    // Present lyrics in a readable format
    $lyrics = nl2br(trim($song->lyrics));

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

    // Services
    $services = array('am' => 'Morning', 'pm' => 'Evening');

    // Next service upload date
    $last_service_uploaded = \Crockenhill\PlayDate::orderBy('date', 'desc')->first(['date']);
    $last_service_uploaded_date = strtotime($last_service_uploaded['date']);
    $next_service_upload_date = strtotime("+7 day", $last_service_uploaded_date);

    // Get NIP titles
    $songs = \Crockenhill\Song::select('id','praise_number','title')
                  ->orderBy('id', 'asc')
                  ->get();

    // Present page
    return view('songs.service-record', array(
      'services'    => $services,
      'lastsunday'  => date('Y-m-d', strtotime('last Sunday')),
      'songs'        => $songs,
      'next_service_upload_date' => date("Y-m-d",$next_service_upload_date),
    ));
  }

  public function postServiceRecord()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    $date = \Input::get('date');

    $services = ['am','pm'];

    foreach ($services as $service) {
      for ($i=1; $i < 10; $i++) {
        if (\Input::get($service.$i, '') != '') {
          $song_id = \Input::get($service.$i);
          if (\Crockenhill\Song::where('id', $song_id)->first()) {
            $song =\Crockenhill\Song::where('id', $song_id)->first();
          }

          $playdate = new \Crockenhill\PlayDate;
          $playdate->song_id = $song->id;
          $playdate->date = $date;
          $playdate->time = $service[0];
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

    // Present page
    return view('songs.create');
  }

  public function store()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    // Get input
    $title        = \Input::get('title');
    $alternative  = \Input::get('alternative_title');
    $category     = \Input::get('major-category');
    $subcategory  = \Input::get('minor-category');
    $author       = \Input::get('author');
    $copyright    = \Input::get('copyright');
    $lyrics       = \Input::get('lyrics');
    $notes        = \Input::get('notes');
    $current      = \Input::get('current');

    // Save new song
    $song = new \Crockenhill\Song;
    $song->title              = $title;
    $song->alternative_title  = $alternative;
    $song->major_category     = $category;
    $song->minor_category     = $subcategory;
    $song->author             = $author;
    $song->copyright          = $copyright;
    $song->lyrics             = $lyrics;
    $song->notes              = $notes;
    $song->current            = $current;
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

    // Present page
    return view('songs.edit', array(
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

    $song->title              = \Input::get('title');
    $song->alternative_title  = \Input::get('alternative_title');
    $song->major_category     = \Input::get('major_category');
    $song->minor_category     = \Input::get('minor_category');
    $song->author             = \Input::get('author');
    $song->copyright          = \Input::get('copyright');
    $song->lyrics             = \Input::get('lyrics');
    $song->notes              = \Input::get('notes');
    $song->current            = \Input::get('current');
    $song->save();

    // Send user back to index
    return redirect('/members/songs')->with('message', '"'.$song->title.'" successfully updated!');
	}

}
