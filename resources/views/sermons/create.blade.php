@extends('page')

@section('dynamic_content')

  <form method="POST" action="/sermons" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    {!! Form::token() !!}

    <div class="form-group">
      <label for="file">File</label>
      <input name="file" type="file" id="file">
    </div>

    <div class="form-group">
      <label for="title">Title</label>
      <input class="form-control h1" id="title" name="title" type="text">
    </div>

    <div class="form-group">
      <label for="date">Date</label>
      @if (date('D') === 'Sun')
        <input type="date" class="form-control" id="date" name="date" value="{!!date('Y-m-d')!!}">
      @else 
        <input type="date" class="form-control" id="date" name="date" value="{!!date('Y-m-d',strtotime('last sunday'))!!}">
      @endif
    </div>

    <div class="form-group">
      <label for="series">Series</label>
      <input class="form-control" id="series" name="series" type="text">
    </div>

    <div class="form-group">
      <label for="reference">Reference</label>
      <input class="form-control" name="reference" type="text" id="reference">
    </div>

    <div class="form-group">
      <label for="preacher">Preacher</label>
      <input class="form-control" id="preacher" name="preacher" type="text">
    </div>

    <div class="form-actions">
      <input class="btn btn-success btn-save btn-large" type="submit" value="Save">
      <a href="{!! URL::route('members.sermons.index') !!}" class="btn btn-large">Cancel</a>
    </div>

  </form>

  <script type="text/javascript">
    document.querySelector('input[type="file"]').onchange = function(e) {
        id3(this.files[0], function(err, tags) {
            // tags now contains your ID3 tags
            console.log(tags);
            document.getElementById('title').value=tags.title; 
            document.getElementById('preacher').value=tags.artist; 
            document.getElementById('series').value=tags.album;
        });
    }
  </script>
 
@stop
