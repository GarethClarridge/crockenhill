@extends('pages.page')

@section('dynamic_content')

  <div class="media song">
    @if ($song->praise_number)
      <div class="media-left media-middle praise-icon">
        <img class="media-object" src="/images/praise.png" alt="">
        <span class="praise-number">{{ $song->praise_number }}</span>
      </div>
    @endif

    <div class="media-body media-middle song-body">
        @if ($song->author)
          <p>
            <span class="glyphicon glyphicon-user"></span> &nbsp
            {{ $song->author }}
          </p>
        @endif

        @if ($last_played)
          <p>
            <span class="glyphicon glyphicon-calendar"></span> &nbsp
            Last Sung: {{date("d F Y",strtotime($last_played))}}
          <p>
        @endif

        @if ($frequency)
          <p>
            <span class="glyphicon glyphicon-info-sign"></span> &nbsp
            
              Sung 
              
              @if ($frequency > 5)
                <span class="label label-success">{{$frequency}}</span>
              @endif

              @if ($frequency > 2)
                <span class="label label-warning">{{$frequency}}</span>
              @endif

              @if ($frequency <= 2)
                <span class="label label-danger">{{$frequency}}</span>
              @endif

               times in the last {{$years}} years

          <p>
        @endif

        @if ($lyrics)
          <i>{{$lyrics}}</i>
        @endif

        @if ($song->copyright)
          <p>
            <span class="glyphicon glyphicon-copyright-mark"></span> &nbsp
            {{ $song->copyright }}
          </p>
        @endif

        @if ($scripture)
          <p>
            <span class="glyphicon glyphicon-book"></span> &nbsp
            Scripture References: 
            @foreach ($scripture as $scripture)
            {{ $scripture->reference }} 
            @endforeach
          </p>
        @endif
      </div>
    </div>

@stop
