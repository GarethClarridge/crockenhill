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

  <h4>Services sung at:</h4>

  <div class="row">
    <div class="col-sm-8">
      <canvas id="service-ratio"></canvas>
    </div>
    <div class="col-sm-4">
      <div id="pieLegend"></div>
    </div>
  </div>

  <h4>Popularity over time:</h4>

  <div class="row">
    <div class="col-sm-12">
      <canvas id="per-year"></canvas>
    </div>
  </div>


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


    var lineData = {
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

    var lineOptions = {
      responsive: true,
      legendTemplate : '<div>'
                  +'<% for (var i=0; i<lineData.length; i++) { %>'
                    +'<p><span class="badge" style=\"background-color:<%=lineData[i].color%>\">'
                    +'<% if (lineData[i].label) { %><%= lineData[i].label %><% } %>'
                    +'</span></p>'
                +'<% } %>'
              +'</div>'
    };

    var ctx2 = document.getElementById("per-year").getContext("2d");
    var myLineChart = new Chart(ctx2).Line(lineData,lineOptions);
  </script>

@stop
