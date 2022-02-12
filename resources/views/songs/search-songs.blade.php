@extends('layouts/page')

@section('dynamic_content')

  @if (count($songs)>0)
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
            <a href="/church/members/songs/{{$song->id}}/{{\Illuminate\Support\Str::slug($song->title)}}">{{$song->title}}</a>
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
  @else
    <p>Sorry, we couldn't find any songs for {{$search}}.</p>

    <div class="d-grid gap-2 mb-3">
      <a href="/church/members/songs/" class="btn btn-primary btn-lg">Go back and search again</a>
    </div>
  @endif

@stop
