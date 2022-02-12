@extends('layouts/page')

@section('dynamic_content')

@can ('edit-songs')
  <div class="d-grid gap-2 mb-3">
    <a href="/church/members/songs/{!!$song->id!!}/{!! \Illuminate\Support\Str::slug($song->title)!!}/edit" class="btn btn-primary btn-lg">
      Edit this song
    </a>
  </div>
@endcan

  <div class="media">
    @if ($song->praise_number)
      <img class="mr-3" src="/images/praise.png" alt="">
      <span class="praise_number praise_number_song">{{ $song->praise_number }}</span>
    @endif

    <div class="media-body">
      @if ($song->author)
        <p>
          <i class="far fa-user"></i> &nbsp
          {{ $song->author }}
        </p>
      @endif

      <p>
        <i class="fas fa-info-circle"></i></span> &nbsp
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

      @if ($song->last_played)
        <p>
          <i class="far fa-calendar"></i> &nbsp
          Last Sung: {{date("d F Y",strtotime($song->last_played))}}
        <p>
      @endif

      @if ($song->major_category != '')
        <p>
          <i class="fas fa-tag"></i> &nbsp
          <span class="song_major_category">{{ $song->major_category }}</span>
          &nbsp &nbsp <i class="fas fa-tag"></i> &nbsp
          @if ($song->minor_category)
              <span class="song_minor_category">{{ $song->minor_category }}</span>
          @endif
        </p>
      @endif

      @if ($song->current === 0)
      <p>
        <i class="fas fa-exclamation-triangle"></i> &nbsp
        This song isn't in our current list of songs to sing. Is there an alternative?
      </p>
      @endif

      @if ($lyrics)
        <i>{!!$lyrics!!}</i>
      @endif

      @if ($song->copyright)
        <p>
          <i class="far fa-copyright"></i> &nbsp
          {{ $song->copyright }}
        </p>
      @endif

      @if ($song->scripture != '')
        <p>
          <i class="fas fa-book"></i> &nbsp
          Scripture References:
            @foreach ($scripture as $s)
              <i>{{ $s->reference }}</i>
            @endforeach
        </p>
      @endif

      @if ($song->notes)
        <p>
          <i class="fas fa-pencil-alt"></i> &nbsp
          {{ $song->notes }}
        </p>
      @endif

    </div>
  </div>

  @if ($song->last_played)
    <h4 class="my-3">Popularity over time:</h4>

    <div class="row">
      <div class="col-sm-12">
        <canvas id="per-year"></canvas>
      </div>
    </div>

    <h4 class="my-3">Services sung at:</h4>

    <div class="row">
      <div class="col-sm-8">
        <canvas id="service-ratio"></canvas>
      </div>
      <div class="col-sm-4">
        <div id="pieLegend"></div>
      </div>
    </div>
  @endif


  <script src="/scripts/Chart.js"></script>
  <script type="text/javascript">

    var pieData = [
        {
            value: {{ $sungmorning }},
            color: "#252a2e",
            highlight: "#3A3F42",
            label: "Morning"
        },
        {
            value: {{ $sungevening }},
            color:"#6a3121",
            highlight: "#784537",
            label: "Evening"
        }
    ];

    var pieOptions = {
      animateRotate: false,
      responsive: true,
      legendTemplate : '<div>'
                  +'<% for (var i=0; i<pieData.length; i++) { %>'
                    +'<p><span class="badge" style=\"background-color:<%=pieData[i].color%>\">'
                    +'<% if (pieData[i].label) { %><%= pieData[i].label %><% } %>'
                    +'</span></p>'
                +'<% } %>'
              +'</div>'
    }

    var ctx1 = document.getElementById("service-ratio").getContext("2d");
    var myPieChart = new Chart(ctx1).Pie(pieData,pieOptions);
    document.getElementById("pieLegend").innerHTML = myPieChart.generateLegend();


    var barData = {
        labels: [
          @foreach ($sungyear as $key => $value)
            {{ $key }},
          @endforeach
        ],
        datasets: [
            {
                label: "My First dataset",
                fillColor: "#C9B4AF",
                strokeColor: "#6a3121",
                pointColor: "#6a3121",
                pointStrokeColor: "#fff",
                pointHighlightFill: "#784537",
                pointHighlightStroke: "#784537",
                data: [
                  @foreach ($sungyear as $key => $value)
                    {{ $value }},
                  @endforeach
                ]
            }
        ]
    };

    var barOptions = {
      responsive: true,
      legendTemplate : '<div>'
                  +'<% for (var i=0; i<barData.length; i++) { %>'
                    +'<p><span class="badge" style=\"background-color:<%=barData[i].color%>\">'
                    +'<% if (barData[i].label) { %><%= barData[i].label %><% } %>'
                    +'</span></p>'
                +'<% } %>'
              +'</div>'
    };

    var ctx2 = document.getElementById("per-year").getContext("2d");
    var myLineChart = new Chart(ctx2).Bar(barData,barOptions);
  </script>

@stop
