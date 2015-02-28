@extends('pages.page')

@section('dynamic_content')

  <br>
  <br>
  <a href="/members/songs/scripture-reference" class="btn btn-primary btn-lg btn-block">Search by scripture reference</a>
  <br>

  @foreach ($songs as $song)
    <div class="media song">
      @if ($song->praise_number)
        <div class="media-left media-middle praise-icon">
          <img class="media-object" src="/images/praise.png" alt="">
          <span class="praise-number">{{ $song->praise_number }}</span>
        </div>
      @endif

      <div class="media-body media-middle song-body">
        <h3 class="media-heading">
          <a href="/members/songs/{{$song->id}}/{{Str::slug($song->title)}}">{{$song->title}}</a>
        </h3>
        @if ($song->author)
          <p>
            <span class="glyphicon glyphicon-user"></span> &nbsp
            {{ $song->author }}
          </p>
        @endif

        @if ($song->copyright)
          <p>
            <span class="glyphicon glyphicon-copyright-mark"></span> &nbsp
            {{ $song->copyright }}
          </p>
        @endif
      </div>
    </div>

  @endforeach

@stop