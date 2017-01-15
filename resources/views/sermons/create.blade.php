@extends('page')

@section('dynamic_content')

  <form method="POST" action="/sermons" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <div class="form-group">
      <label class="btn btn-primary btn-lg" for="file">
        <span class="glyphicon glyphicon-folder-open"></span>
        &nbsp Choose file
        <input name="file" type="file" id="file" class="hidden">
      </label>
       &nbsp
      <span id="filename" class="alert alert-success hidden">
        <span class="glyphicon glyphicon-ok"></span>
         &nbsp
      </span>
    </div>

    <h2>Sermon details</h2>

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
      <label for="service">Service</label>
      @if (time() <= strtotime('15:00:00'))
        <select type="service" class="form-control" id="service" name="service">
          <option value="morning" selected>Morning</option>
          <option value="evening">Evening (or afternoon)</option>
        </select>
      @else
        <select type="service" class="form-control" id="service" name="service">
          <option value="morning">Morning</option>
          <option value="evening" selected>Evening (or afternoon)</option>
        </select>
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

    <h2>Sermon points</h2>

    @for ($p = 1; $p < 7; $p++)
      <div class="form-group">
        <div class="row">
          <div class="col-md-2">
            <label for="point-{{$p}}">Point {{$p}}</label>
          </div>
          <div class="col-md-10">
            <input class="form-control h2 input-lg" type="text" name="point-{{$p}}" id="point-{{$p}}" data-id="{{$p}}">
          </div>
        </div>

        @for ($i = 1; $i < 6; $i++)
          <div class="row">
            <div class="col-md-3">
              <label for="sub-point-{{$p}}-{{$i}}">Sub-point {{$p}}.{{$i}}</label>
            </div>
            <div class="col-md-9">
              <input class="form-control" type="text" name="sub-point-{{$p}}-{{$i}}" id="sub-point-{{$p}}-{{$i}}" data-id="{{$p}}.{{$i}}">
            </div>
          </div>
        @endfor
      </div>
    @endfor

    <div class="form-actions">
      <input class="btn btn-success btn-save btn-large" type="submit" value="Save">
      <a href="/sermons" class="btn btn-large">Cancel</a>
    </div>

  </form>

  <script type="text/javascript">
    document.querySelector('input[type="file"]').onchange = function(e) {
        id3(this.files[0], function(err, tags) {
            // tags now contains your ID3 tags
            document.getElementById('title').value    = tags.title;
            document.getElementById('preacher').value = tags.artist;
            document.getElementById('series').value   = tags.album;
        });
        var span = document.getElementById('filename')
        span.innerHTML = span.innerHTML + document.querySelector('input[type="file"]').value;
        span.classList.remove("hidden");
    }
  </script>

@stop
