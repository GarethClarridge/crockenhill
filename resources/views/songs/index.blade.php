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

 <br>

  <section id="song-list">
    <div class="form-group" id="song-list-controls">
      <label for="text-filter">Filter songs</label>
      <input class="search form-control" id='text-filter' />
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
    </div>

    <ul class="list">

      @foreach ($songs as $song)
        <li class="media song">
          <div class="media-left media-middle praise-icon">
            @if ($song->praise_number)
              <img class="media-object" src="/images/praise.png" alt="">
              <span class="praise-number">{!! $song->praise_number !!}</span>
            @else
              <img class="media-object" src="/images/Primary.png" width="128px" height="128px" alt="">
            @endif
          </div>

          <div class="media-body media-middle song-body">
            <h3 class="media-heading">
                <a href="/members/songs/{!!$song->id!!}/{!! \Illuminate\Support\Str::slug($song->title)!!}" class="song-title">{{$song->title}}</a>
            </h3>
            @if ($song->author)
              <p>
                <span class="glyphicon glyphicon-user"></span> &nbsp
                <span class="song-author">{!! $song->author !!}</span>
              </p>
            @endif

            @if ($song->last_played)
              <p>
                <span class="glyphicon glyphicon-info-sign"></span> &nbsp

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
            @endif

          </div>
        </li>

      @endforeach
    </section>

  <script src="/scripts/list.min.js"></script>
  <script type="text/javascript">
    var options = {
      valueNames: [ 'song-title', 'song-author', 'praise-number', 'song-frequency' ]
    };

    var songList = new List('song-list', options);
  </script>

@stop
