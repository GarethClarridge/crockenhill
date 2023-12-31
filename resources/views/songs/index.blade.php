@extends('layouts/page')

@section('dynamic_content')

@can ('edit-songs')
<br>
<p>You last uploaded a service record on <strong>{{date("d F Y",strtotime($last_service_uploaded['date']))}}</strong>.</p>
<div class="flex flex-wrap ">
  <div class="sm:w-1/2 pr-4 pl-4">
    <div class="d-grid gap-2 mb-3">
      <a href="/church/members/songs/service-record" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-blue-600 hover:bg-blue-600 py-3 px-4 leading-tight text-xl">
    </div>
    Upload a new service record
    </a>
  </div>
  <div class="sm:w-1/2 pr-4 pl-4">
    <div class="d-grid gap-2 mb-3">
      <a href="/church/members/songs/create" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-blue-600 hover:bg-blue-600 py-3 px-4 leading-tight text-xl">
    </div>
    Upload a new song
    </a>
  </div>
</div>
<br>
@endcan

<section id="song-list">
  <div class="song-filters">
    <div class="mb-3">
      <label for="song-text-filter">Filter songs</label>
      <input class="fuzzy-search block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id='song-text-filter' placeholder="Try typing a song title, Praise! number, category or author" />
    </div>

    <div class="mb-3" id="in-praise-filter">
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

    <div class="mb-3" id="category-filter">

      <div class="major-category-filter-div">
        <label for="major-category-filter">Category:</label>
        <select name="major-category-filter" id="major-category-filter" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" onchange="updateFilter()">
          <option value="All">All</option>
          <option value="Psalms">Psalms</option>
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
        <select class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="minor-category-filter" id="minor-category-filter" onchange="updateFilter()">
          <option id="minor-category-filter-option" value="All">All</option>
        </select>
      </div>

    </div>

    <div class="mb-3">
      <label for="sort">Sort by:</label>
      <select class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="sort" id="sort" onchange="updateSort()">
        <option value="song-frequency">Popularity</option>
        <option value="song-title">Title</option>
      </select>
    </div>
  </div>

  @foreach ($songs as $song)
  <div class="relative flex flex-col min-w-0 rounded break-words border bg-white border-1 border-gray-300 p-0 my-2">
    <div class="flex-auto p-6 pb-0">
      <div class="flex items-start" data-nip="{{$song->nip}}">
        @if ($song->praise_number)
        <img class="mr-3" src="/images/praise.png" alt="">
        <span class="praise_number">{!! $song->praise_number !!}</span>
        @else
        <img class="mr-3" src="/images/nip.png" width="128px" height="128px" alt="">
        @endif

        @if (!$song->last_played || $song->current===0)
        <div class="flex-1 song-unknown">
          @else
          <div class="flex-1">
            @endif
            <h3 class="mt-0 mb-3">
              <a href="/church/members/songs/{!!$song->id!!}/{!! \Illuminate\Support\Str::slug($song->title)!!}" class="song-title">{{$song->title}}</a>
            </h3>

            @if ($song->author)
            <p>
              <span class="song-author">{!! $song->author !!}</span>
            </p>
            @endif

            @if ($song->frequency)
            <p>
              @if ($song->frequency > 5)
              <span class="label label-success song-frequency">{{$song->frequency}}</span>
              @endif

              @if ($song->frequency > 1 && $song->frequency <= 5) <span class="label label-warning song-frequency">{{$song->frequency}}</span>
                @endif

                @if ($song->frequency <= 1) <span class="label label-danger song-frequency">{{$song->frequency}}</span>
                  @endif

                  times in the last 2 years
            </p>
            @else
            @if ($song->last_played)
            <p>
              Last Sung: {{date("d F Y",strtotime($song->last_played))}}
            <p>
              @else
            <p>
              We've never sung this song.
              @endif
              @endif

              @if ($song->major_category != '')
            <p>
              <span class="song_major_category">{{ $song->major_category }}</span>
              @if (!empty($song->minor_category))
              <span class="song_minor_category">{{ $song->minor_category }}</span>
              @endif
            </p>
            @endif

            @if ($song->notes)
            <p>
              {{ $song->notes }}
            </p>
            @endif

            @if ($song->current === 0)
            <p>
              This song isn't in our current list of songs to sing. Is there an alternative?
            </p>
            @endif
          </div>
        </div>
      </div>
    </div>
    @endforeach
</section>

<script src="/scripts/list.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/list.fuzzysearch.js/0.1.0/list.fuzzysearch.js"></script>
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
      {
        data: ['nip']
      }
    ],
    plugins: [ListFuzzySearch()]
  };

  var songList = new List('song-list', options);

  function updateFilter() {
    songList.filter(function(item) {

      // Option filters - remove items which don't match
      if (($("input[type='radio'][name='in-praise']:checked").val() !== 'both' &&
          $("input[type='radio'][name='in-praise']:checked").val() !== item.values().nip) ||
        ($("#major-category-filter").val() !== 'All' &&
          $("#major-category-filter").val().trim() !== item.values().song_major_category.trim()) ||
        ($("#minor-category-filter").val() !== 'All' &&
          $("#minor-category-filter").val().trim() !== item.values().song_minor_category.trim())) {
        return false;
      } else {
        return true;
      }
    });

    updateSort();
  }

  function updateSort() {
    if ($('#sort')[0].value == 'song-frequency') {
      songList.sort($('#sort')[0].value, {
        order: "desc"
      });
    } else {
      songList.sort($('#sort')[0].value, {
        order: "asc"
      });
    }
  }

  var categories = {
    "Psalms": [],
    "Approaching God": ["The eternal Trinity",
      "Adoration and thanksgiving",
      "Creator and sustainer",
      "Morning and evening",
      "The Lord’s Day",
      "Beginning and ending of the year"
    ],
    "The Father": ["His character",
      "His providence",
      "His love",
      "His covenant"
    ],
    "The Son": ["His name and praise",
      "His birth and childhood",
      "His life and ministry",
      "His suffering and death",
      "His resurrection",
      "His ascension and reign",
      "His priesthood and intercession",
      "His return in glory"
    ],
    "The Holy Spirit": ["His person and power",
      "His presence in the church",
      "His work in revival"
    ],
    "The Bible": ["Authority and sufficiency",
      "Enjoyment and obedience"
    ],
    "The church": ["Character and privileges",
      "Fellowship",
      "Gifts and ministries",
      "The life of prayer",
      "Evangelism and mission",
      "Baptism",
      "The Lord’s Supper"
    ],
    "The gospel": ["Invitation and warning",
      "Crying out for God",
      "New birth and new life",
      "Repentance and faith"
    ],
    "The Christian life": ["Union with Christ",
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
    "Christ’s lordship over all of life": ["The earth and harvest",
      "Christian citizenship",
      "Christian marriage",
      "Families and children",
      "Health and healing",
      "Work and leisure",
      "Those in need",
      "Government and nations"
    ],
    "The future": ["The resurrection of the body",
      "Judgement and hell",
      "Heaven and glory"
    ]
  };

  $("#major-category-filter").change(function() {
    var selection = this.value;

    if (selection === 'All') {
      $("#minor-category-filter-div").hide();
    } else {

      var seloption = '<option id="minor-category-filter-option" value="All">All</option>';
      var optionsarray = categories[selection];

      $.each(optionsarray, function(i) {
        seloption += '<option class="generated-option" value="' + optionsarray[i] + '">' + optionsarray[i] + '</option>';
      });

      $(".generated-option").remove();
      $("#minor-category-filter-option").replaceWith(seloption);

      $("#minor-category-filter-div").show();
    }
  });
</script>

@stop