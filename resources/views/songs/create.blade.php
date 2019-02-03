@extends('page')

@section('dynamic_content')

  <form method="POST" action="/members/songs" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    <div class="form-group">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">

      <br>
      <label for="title">Title</label>
      <input class="form-control" name="title" type="text" id="title">

      <br>
      <label for="alternative-title">Alternative Title</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="alternative_title" type="text" id="alternative-title">

      <br>
      <label for="major-category">Praise! category</label>
      <select name="major-category" id="major-category" class="form-control">
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

      <br>
      <label for="minor-category">Praise! sub-category</label>
      <select class="form-control" name="minor-category" id="minor-category">
        <option id="minor-category" value="All">All</option>
      </select>

      <br>
      <label for="author">Author</label>
      <small><i>of the lyrics, may not be the composer of the music.</i></small>
      <input class="form-control" name="author" type="text" id="author">

      <br>
      <label for="copyright">Copyright</label>
      <small><i>to the lyrics, may not be the same as the music.</i></small>
      <input class="form-control" name="copyright" type="text" id="copyright">

      <br>
      <label for="lyrics">Lyrics</label>
      <small><b>Do not upload lyrics unless the song is out of copyright.</b></small>
      <textarea class="form-control" name="lyrics" id="lyrics"></textarea>

      <br>
      <label for="notes">Notes</label>
      <textarea class="form-control" name="notes" id="notes"></textarea>

      <br>
      <label for="current">In current list?</label>
      <input type="checkbox" name="current" checked value=1>

      <br>
      <br>
      <input class="btn btn-success btn-lg btn-block" type="submit" value="Save">
    </div>
  </form>

  <script src="/scripts/jquery.min.js"></script>
  <script type="text/javascript">
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

  $("#major-category").change(function () {
      var selection = this.value;

      var optionsarray = categories[selection];

      $("#minor-category").empty();

      $.each(optionsarray,function(i){
          $("#minor-category").append('<option value="'+optionsarray[i]+'">'+optionsarray[i]+'</option>');
      });

  });
  </script>

@stop
