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

 <!-- {!! Form::open(array('url' => '/members/songs/scripture-reference-search')) !!}
   <div class="form-group">
     <div class="row">
       <div class="col-sm-3">
         {!! Form::label('book', 'Book') !!}
         {!! Form::select('book',
           $books,
           null,
           array('class' => 'form-control')) !!}
       </div>
       <div class="col-sm-2">
         {!! Form::label('chapter', 'Chapter') !!}
         {!! Form::number('chapter', 'value', array('class' => 'form-control')) !!}
       </div>
       <div class="col-sm-2">
         {!! Form::label('verse', 'Verse') !!}
         {!! Form::number('verse', 'value', array('class' => 'form-control')) !!}
       </div>
       <div class="col-sm-5">
         <br>
         {!! Form::submit('Search by scripture reference', array('class' => 'btn btn-primary btn-block')) !!}
       </div>
     </div>
   </div>
 {!! Form::close() !!}

 {!! Form::open(array('url' => '/members/songs/search')) !!}
   <div class="form-group">
     <div class="row">
       <div class="col-sm-7">
         {!! Form::label('search', 'Text search') !!}
         {!! Form::text('search', null, array('class' => 'form-control')) !!}
       </div>
       <div class="col-sm-5">
         <br>
         {!! Form::submit('Search for text', array('class' => 'btn btn-primary btn-block')) !!}
       </div>
     </div>
   </div>
 {!! Form::close() !!} -->
 <br>

  <section id="song-list">
    <div class="form-group" id="song-list-controls">
      <label for="text-filter">Filter songs</label>
      <input class="search form-control" id='text-filter' />

      <button class="sort btn btn-default" data-sort="song-title">
        Sort by title
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

            @if ($song->copyright)
              <p>
                <span class="glyphicon glyphicon-copyright-mark"></span> &nbsp
                {!! $song->copyright !!}
              </p>
            @endif
          </div>
        </li>

      @endforeach
    </section>

  <script src="/scripts/list.min.js"></script>
  <script type="text/javascript">
    var options = {
      valueNames: [ 'song-title', 'song-author', 'praise-number' ]
    };

    var songList = new List('song-list', options);
  </script>

@stop
