<?php

class SongController extends BaseController {

    public function song($song_id)
    {
        // Get information about the current song
        $current_song = Song::where('song_id', $song_id)->first();

        // Information about when last played
        $last_played = Song::find($song_id)->playdates()->orderBy('date', 'desc')->first();
        if ($last_played != '') {
            
            $last_played_date = date ('jS \of F Y', strtotime($last_played->date));
            $parse_date = date_parse($last_played->date);
            if ($parse_date['hour'] == 10) {
                $last_played_service = 'morning';
            } else if ($parse_date['hour'] == 18) {
                $last_played_service = 'evening';
            }
        } else {
            $last_played_date = '';
            $last_played_service = '';
        }

        // Information about familiarity
        $time_limit = strtotime("-3 year", time());
        $time_limit_date = date('Y-m-d h:i:s', $time_limit);
        $frequency = Song::find($song_id)->playdates()->where('date', '>', $time_limit_date)->count();

        // Return a view
        return View::make('pages.members.songs.song', array('song' => $current_song, 'last_played_date' => $last_played_date, 'last_played_service' => $last_played_service, 'frequency' => $frequency));
    }



    public function index() {

        // get all the songs from the database
        $songs = Song::orderBy('title', 'asc')->get();

        // and create a view
        return View::make('pages.members.songs.list', array('songs' => $songs));

    }



    public function keywordsearch() {

        // Process input
        $keyword = Input::get('search');
        $keyword = str_replace('_', ' ', $keyword);

        // get songs which match keyword
        $songs = Song::where('title', 'like', '%'.$keyword.'%')->orWhere('lyrics', 'like', '%'.$keyword.'%')->orderBy('title', 'asc')->get();

        // and create a view
        return View::make('pages.members.songs.keywordsearch', array('songs' => $songs, 'keyword' => $keyword));

    }



    public function scripturesearch() {

        // Process input
        $search = Input::get('book') .'.'. Input::get('chapter') .'.'. Input::get('verse');

        // Search for songs that match scripturesearch

        $songs = Song::join('scripture_references', 'songs.song_id', '=', 'scripture_references.song_id')->where('reference', 'like', $search)->get();

        // Create a view
        return View::make('pages.members.songs.scripturesearch', array('songs' => $songs, 'search' => $search));

    }
}
