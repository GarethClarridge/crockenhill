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
      <div class="form-group">
        <label for="song-text-filter">Filter songs</label>
        <input  class="search form-control"
                id='song-text-filter'
                placeholder="Try typing a song title, Praise! number, category or author"/>
      </div>

      <div class="form-group" id="in-praise-filter">
        <label>
          <input type="radio" name="in-praise" value="praise" id="filter-praise" onchange="updateFilter()"> Only in Praise!
        </label>
        <label>
          <input type="radio" name="in-praise" value="both" id="filter-both-praise-nip" checked="checked" onchange="updateFilter()"> Both
        </label>
        <label>
          <input type="radio" name="in-praise" value="nip" id="filter-nip" onchange="updateFilter()"> Only not in Praise!
        </label>
      </div>

      <div class="form-group" id="category-filter">

        <div class="major-category-filter-div">
          <label for="major-category-filter">Category:</label>
          <select name="major-category-filter" id="major-category-filter" class="form-control" onchange="updateFilter()">
            <option value="All">All</option>
            <option value="Approaching God">Approaching God</option>
            <option value="The Father">The Father</option>
            <option value="The Son">The Son</option>
            <option value="The Holy Spirit">The Holy Spirit</option>
            <option value="The Bible">The Bible</option>
            <option value="The church">The church</option>
            <option value="The gospel">The gospel</option>
            <option value="The Christian life">The Christian life</option>
            <option value="Christ's lordship over all of life">Christ's lordship over all of life</option>
            <option value="The future">The future</option>
          </select>
        </div>

        <div id="minor-category-filter-div" style="display:none;">
          <label for="minor-category-filter">Sub-category:</label>
          <select class="form-control" name="minor-category-filter" id="minor-category-filter" onchange="updateFilter()">
            <option id="minor-category-filter-option" value="All">All</option>
          </select>
        </div>

      </div>

      <div class="form-group">
        <label for="sort">Sort by:</label>
        <select class="form-control" name="sort" id="sort" onchange="updateSort()">
          <option value="song-frequency">Popularity</option>
          <option value="song-title">Title</option>
        </select>
      </div>
    </div>

    <ul class="list">

      @foreach ($songs as $song)
        <li class="media song" data-nip="{{$song->nip}}">
          <div class="media-left media-middle praise-icon">
            @if ($song->praise_number)
              <img class="media-object" src="/images/praise.png" alt="">
              <span class="praise_number">{!! $song->praise_number !!}</span>
            @else
              <img class="media-object" src="/images/nip.png" width="128px" height="128px" alt="">
            @endif
          </div>

          @if (!$song->last_played || $song->current===0)
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

            @if ($song->frequency)
              <p>
                <span class="glyphicon glyphicon-info-sign"></span> &nbsp Sung
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
              @if ($song->last_played)
                <p>
                  <span class="glyphicon glyphicon-calendar"></span> &nbsp
                  Last Sung: {{date("d F Y",strtotime($song->last_played))}}
                <p>
              @else
              <p>
                <span class="glyphicon glyphicon-info-sign"></span> &nbsp
                  We've never sung this song.
              @endif
            @endif

            @if ($song->major_category != '')
              <p>
                <span class="glyphicon glyphicon-tag"></span> &nbsp
                <span class="song_major_category">{{ $song->major_category }}</span>
                @if (!empty($song->minor_category))
                  &nbsp &nbsp <span class="glyphicon glyphicon-tag"></span> &nbsp
                  <span class="song_minor_category">{{ $song->minor_category }}</span>
                @endif
              </p>
            @endif

            @if ($song->notes)
              <p>
                <span class="glyphicon glyphicon-pencil"></span> &nbsp
                {{ $song->notes }}
              </p>
            @endif

            @if ($song->current === 0)
            <p>
              <span class="glyphicon glyphicon-warning-sign"></span> &nbsp
              This song isn't in our current list of songs to sing. Is there an alternative?
            </p>
            @endif

          </div>
        </li>

      @endforeach
    </section>

  <script src="/scripts/list.min.js"></script>
  <script src="/scripts/jquery.min.js"></script>
  <script type="text/javascript">
    var options = {
      valueNames: [
        'song-title',
        'song-author',
        'praise_number',
        'song-frequency',
        'song_major_category',
        'song_minor_category',
        { data: ['nip'] }
      ],
      plugins: [ ListFuzzySearch() ] 
    };

    var songList = new List('song-list', options);

    function updateFilter(){
      songList.filter(function(item) {

        // Option filters - remove items which don't match
        if (      ($("input[type='radio'][name='in-praise']:checked").val() !== 'both'
                        && $("input[type='radio'][name='in-praise']:checked").val() !== item.values().nip)
              ||  ($("#major-category-filter").val() !== 'All'
                        && $("#major-category-filter").val().trim() !== item.values().song_major_category.trim())
              ||  ($("#minor-category-filter").val() !== 'All'
                        && $("#minor-category-filter").val().trim() !== item.values().song_minor_category.trim()))
              {
                return false;
              }
        else
              {
                return true;
              }
      });

      updateSort();
    }

    function updateSort(){
      if ($('#sort')[0].value == 'song-frequency') {
        songList.sort($('#sort')[0].value, {
          order: "desc"
        });
      }
      else {
       songList.sort($('#sort')[0].value, {
         order: "asc"
       });
     }
    }

    var categories = {
      "Approaching God"                     : [ "The eternal Trinity",
                                                "Adoration and thanksgiving",
                                                "Creator and sustainer",
                                                "Morning and evening",
                                                "The Lord’s Day",
                                                "Beginning and ending of the year"
                                              ],
      "The Father"                          : [ "His character",
                                                "His providence",
                                                "His love",
                                                "His covenant"
                                              ],
      "The Son"                             : [ "His name and praise",
                                                "His birth and childhood",
                                                "His life and ministry",
                                                "His suffering and death",
                                                "His resurrection",
                                                "His ascension and reign",
                                                "His priesthood and intercession",
                                                "His return in glory"
                                              ],
      "The Holy Spirit"                     : [ "His person and power",
                                                "His presence in the church",
                                                "His work in revival"
                                              ],
      "The Bible"                           : [ "Authority and sufficiency",
                                                "Enjoyment and obedience"
                                              ],
      "The church"                          : [ "Character and privileges",
                                                "Fellowship",
                                                "Gifts and ministries",
                                                "The life of prayer",
                                                "Evangelism and mission",
                                                "Baptism",
                                                "The Lord’s Supper"
                                              ],
      "The gospel"                          : [ "Invitation and warning",
                                                "Crying out for God",
                                                "New birth and new life",
                                                "Repentance and faith"
                                              ],
      "The Christian life"                  : [ "Union with Christ",
                                                "Love for Christ",
                                                "Freedom in Christ",
                                                "Submission and trust",
                                                "Assurance and hope",
                                                "Peace and joy",
                                                "Holiness",
                                                "Humbling and restoration",
                                                "Commitment and obedience",
                                                "Zeal in service",
                                                "Guidance",
                                                "Suffering and trial",
                                                "Spiritual warfare",
                                                "Perseverance",
                                                "Facing death"
                                              ],
      "Christ’s lordship over all of life"  : [ "The earth and harvest",
                                                "Christian citizenship",
                                                "Christian marriage",
                                                "Families and children",
                                                "Health and healing",
                                                "Work and leisure",
                                                "Those in need",
                                                "Government and nations"
                                              ],
      "The future"                          : [ "The resurrection of the body",
                                                "Judgement and hell",
                                                "Heaven and glory"
                                              ]
    };

    $("#major-category-filter").change(function () {
        var selection = this.value;

        if (selection === 'All'){
          $("#minor-category-filter-div").hide();
        }
        else {

          var seloption = '<option id="minor-category-filter-option" value="All">All</option>';
          var optionsarray = categories[selection];

          $.each(optionsarray,function(i){
              seloption += '<option class="generated-option" value="'+optionsarray[i]+'">'+optionsarray[i]+'</option>';
          });

          $(".generated-option").remove();
          $("#minor-category-filter-option").replaceWith(seloption);

          $("#minor-category-filter-div").show();
        }
    });

  </script>

@stop
