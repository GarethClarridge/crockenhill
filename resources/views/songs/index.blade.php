@extends('page')

@section('dynamic_content')

 <br>
 <br>
 <div class="row">
   <div class="col-sm-6">
     <a href="/members/songs/service-record" class="btn btn-default btn-lg btn-block">
       <span class="glyphicon glyphicon-upload" aria-hidden="true"></span> &nbsp
       Upload a new service record
     </a>
   </div>
   <div class="col-sm-6">
     <a href="/members/songs/create" class="btn btn-default btn-lg btn-block">
       <span class="glyphicon glyphicon-upload" aria-hidden="true"></span> &nbsp
       Upload a new song
     </a>
   </div>
 </div>
 <br>

  <section id="song-list">
    <div class="song-filters">
        <label for="text-filter">Filter songs</label>
        <input  class="search form-control"
                id='text-filter'
                placeholder="Try typing a song title, Praise! number, category or author"/>
        <br>
        Sort by: &nbsp &nbsp
        <button class="sort btn btn-default" data-sort="song-title">
          Title
        </button>
        <button class="sort btn btn-default" data-sort="praise-number">
          Praise! number
        </button>
        <button class="sort btn btn-default" data-sort="song-frequency">
          Popularity
        </button>
        <br>
        <!-- Filter: &nbsp &nbsp
        <select class="filter">
          <option value="value3">Value 3</option>
        </select> -->
    </div>

    <ul class="list">

      @foreach ($songs as $song)
        <li class="media song">
          <div class="media-left media-middle praise-icon">
            @if ($song->praise_number)
              <img class="media-object" src="/images/praise.png" alt="">
              <span class="praise-number">{!! $song->praise_number !!}</span>
            @else
              <img class="media-object" src="/images/nip.png" width="128px" height="128px" alt="">
            @endif
          </div>

          @if (!$song->last_played)
            <div class="media-body media-middle song-body song-unknown">
          @else
            <div class="media-body media-middle song-body">
          @endif
            <h3 class="media-heading">
                <a href="/members/songs/{!!$song->id!!}/{!! \Illuminate\Support\Str::slug($song->title)!!}" class="song-title">{{$song->title}}</a>
            </h3>
            @if ($song->author)
              <p>
                <span class="glyphicon glyphicon-user"></span> &nbsp
                <span class="song-author">{!! $song->author !!}</span>
              </p>
            @endif


            <p>
              <span class="glyphicon glyphicon-info-sign"></span> &nbsp
            @if ($song->last_played)
              Sung

              @if ($song->frequency > 5)
                <span class="label label-success song-frequency">{{$song->frequency}}</span>
              @endif

              @if ($song->frequency > 1 && $song->frequency <= 5)
                <span class="label label-warning song-frequency">{{$song->frequency}}</span>
              @endif

              @if ($song->frequency <= 1)
                <span class="label label-danger song-frequency">{{$song->frequency}}</span>
              @endif

               times in the last 2 years
            </p>
            @else
              We've never sung this song.
            @endif

            @if ($song->major_category)
              <p>
                <span class="glyphicon glyphicon-tag"></span> &nbsp
                <span class="song-major-category">{{ $song->major_category }}</span>
                &nbsp &nbsp <span class="glyphicon glyphicon-tag"></span> &nbsp
                @if ($song->minor_category)
                    <span class="song-minor-category">{{ $song->minor_category }}</span>
                @endif
              </p>
            @endif



          </div>
        </li>

      @endforeach
    </section>

  <script src="/scripts/list.min.js"></script>
  <script type="text/javascript">
    var options = {
      valueNames: [ 'song-title', 'song-author', 'praise-number', 'song-frequency', 'song-major-category', 'song-minor-category' ]
    };

    var songList = new List('song-list', options);
  </script>

@stop
