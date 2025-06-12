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
    try {
      // Load songs
      $songs = \Crockenhill\Song::all();

      foreach ($songs as $song) {
        $last_played_record = \Crockenhill\PlayDate::where('song_id', $song->id)
                                  ->orderBy('date', 'desc')
                                  ->first();
        $song['last_played'] = $last_played_record ? $last_played_record->date : null;

        // Information about how often we've sung it recently
        $years = 2;
        $frequency = \Crockenhill\PlayDate::where('song_id', $song->id)
                                  ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
                                  ->count();
        $song['frequency'] = $frequency >= 1 ? $frequency : 0;
        $song['nip'] = $song->praise_number === null ? 'nip' : 'praise';
      }

      $last_service_uploaded = \Crockenhill\PlayDate::orderBy('date', 'desc')->first(['date']);

      return view('songs.index', [
        'songs' => $songs->sortByDesc('frequency'),
        'last_service_uploaded' => $last_service_uploaded
      ]);
    } catch (\Exception $e) {
      \Log::error('Error in SongController@index: ' . $e->getMessage());
      return view('songs.index', [
        'songs' => collect(),
        'last_service_uploaded' => null
      ]);
    }
  }

  public function show($id)
  {
    try {
      $song = \Crockenhill\Song::findOrFail($id);
      $lyrics = nl2br(trim($song->lyrics));

      $last_played_record = \Crockenhill\PlayDate::where('song_id', $song->id)
                                ->orderBy('date', 'desc')
                                ->first();
      $song['last_played'] = $last_played_record ? $last_played_record->date : null;

      $years = 2;
      $frequency = \Crockenhill\PlayDate::where('song_id', $song->id)
                                ->where('date', '>', date('Y-m-d', strtotime("-".$years." years")))
                                ->count();
      $song['frequency'] = $frequency >= 1 ? $frequency : 0;

      $scripture = \Crockenhill\ScriptureReference::where('song_id', $song->id)->get();

      $sung_morning = \Crockenhill\PlayDate::where('song_id', $song->id)
                                ->where('time', 'a')
                                ->count();
      $sung_evening = \Crockenhill\PlayDate::where('song_id', $song->id)
                                ->where('time', 'p')
                                ->count();

      $sung_year = [];
      $now = date('Y');
      while ($now > 2003) {
        $times = \Crockenhill\PlayDate::where('song_id', $song->id)
                            ->where('date', 'LIKE', $now.'%')
                            ->count();
        $sung_year[$now] = $times;
        $now--;
      }

      return view('songs.show', [
        'song' => $song,
        'lyrics' => $lyrics,
        'last_played' => $song['last_played'],
        'frequency' => $song['frequency'],
        'years' => $years,
        'scripture' => $scripture,
        'sungmorning' => $sung_morning,
        'sungevening' => $sung_evening,
        'sungyear' => array_reverse($sung_year, true),
        'year' => date('Y')
      ]);
    } catch (\Exception $e) {
      \Log::error('Error in SongController@show: ' . $e->getMessage());
      abort(404);
    }
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

    $date = \Request::input('date');

    $services = ['am','pm'];

    foreach ($services as $service) {
      for ($i=1; $i < 10; $i++) {
        if (\Request::input($service.$i, '') != '') {
          $song_id = \Request::input($service.$i);
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
    return redirect('/church/members/songs/');
  }

  public function create()
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    return view('songs.create');
  }

  public function store(Request $request)
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    $validated = $request->validate([
      'title' => 'required|string|max:255',
      'lyrics' => 'required|string',
      'copyright' => 'nullable|string|max:255',
      'ccli_number' => 'nullable|string|max:50',
      'scripture_references' => 'nullable|array'
    ]);

    try {
      $song = \Crockenhill\Song::create($validated);

      if ($request->has('scripture_references')) {
        foreach ($request->scripture_references as $reference) {
          \Crockenhill\ScriptureReference::create([
            'song_id' => $song->id,
            'reference_string' => $reference
          ]);
        }
      }

      return redirect('/church/members/songs')->with('success', 'Song created successfully');
    } catch (\Exception $e) {
      \Log::error('Error creating song: ' . $e->getMessage());
      return back()->withInput()->withErrors(['error' => 'Error creating song']);
    }
  }

  public function edit($id)
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    try {
      $song = \Crockenhill\Song::findOrFail($id);
      $scripture = \Crockenhill\ScriptureReference::where('song_id', $id)->get();
      
      return view('songs.edit', [
        'song' => $song,
        'scripture' => $scripture
      ]);
    } catch (\Exception $e) {
      \Log::error('Error in SongController@edit: ' . $e->getMessage());
      abort(404);
    }
  }

  public function update(Request $request, $id)
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    $validated = $request->validate([
      'title' => 'required|string|max:255',
      'lyrics' => 'required|string',
      'copyright' => 'nullable|string|max:255',
      'ccli_number' => 'nullable|string|max:50',
      'scripture_references' => 'nullable|array'
    ]);

    try {
      $song = \Crockenhill\Song::findOrFail($id);
      $song->update($validated);

      if ($request->has('scripture_references')) {
        \Crockenhill\ScriptureReference::where('song_id', $id)->delete();
        foreach ($request->scripture_references as $reference) {
          \Crockenhill\ScriptureReference::create([
            'song_id' => $id,
            'reference_string' => $reference
          ]);
        }
      }

      return redirect('/church/members/songs')->with('success', 'Song updated successfully');
    } catch (\Exception $e) {
      \Log::error('Error updating song: ' . $e->getMessage());
      return back()->withInput()->withErrors(['error' => 'Error updating song']);
    }
  }

  public function destroy($id)
  {
    if (\Gate::denies('edit-songs')) {
      abort(403);
    }

    try {
      $song = \Crockenhill\Song::findOrFail($id);
      \Crockenhill\ScriptureReference::where('song_id', $id)->delete();
      $song->delete();
      return redirect('/church/members/songs')->with('success', 'Song deleted successfully');
    } catch (\Exception $e) {
      \Log::error('Error deleting song: ' . $e->getMessage());
      return redirect('/church/members/songs')->with('error', 'Error deleting song');
    }
  }

  public function search(Request $request)
  {
    $query = $request->input('q');
    $songs = \Crockenhill\Song::where('title', 'like', "%{$query}%")
      ->orWhere('lyrics', 'like', "%{$query}%")
      ->get();

    return view('songs.index', [
      'songs' => $songs,
      'search_query' => $query
    ]);
  }

  public function byScripture($reference)
  {
    $songs = \Crockenhill\Song::whereHas('scriptureReferences', function($query) use ($reference) {
      $query->where('reference_string', 'like', "%{$reference}%");
    })->get();

    return view('songs.index', [
      'songs' => $songs,
      'scripture_reference' => $reference
    ]);
  }

}
