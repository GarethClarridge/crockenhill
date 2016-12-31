@extends('page')

@section('dynamic_content')

  <form method="POST" action="/members/songs/{{$song->id}}/{{$song->title}}/edit" accept-charset="UTF-8" enctype="multipart/form-data">
    <div class="form-group">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">

      <br />
      <label for="title">Title</label>
      <input class="form-control" name="title" type="text" id="title" value="{{$song->title}}">

      <br>
      <label for="alternative_title">Alternative title</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="alternative_title" type="text" id="alternative_title" value="{{$song->alternative_title}}">

      <br>
      <label for="major_category">Praise! category</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="major_category" type="text" id="major_category" value="{{$song->major_category}}">

      <br>
      <label for="minor_category">Sub-category</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="minor_category" type="text" id="minor_category" value="{{$song->minor_category}}">

      <br>
      <label for="author">Author</label>
      <small><i>of the lyrics, may not be the composer of the music.</i></small>
      <input class="form-control" name="author" type="text" id="author" value="{{$song->author}}">

      <br>
      <label for="copyright">Copyright</label>
      <small><i>to the lyrics, may not be the same as the music.</i></small>
      <input class="form-control" name="copyright" type="text" id="copyright" value="{{$song->copyright}}">

      <br>
      <label for="lyrics">Lyrics</label>
      <small><b>Do not upload lyrics unless the song is out of copyright.</b></small>
      <textarea class="form-control" name="lyrics" type="text" id="lyrics">{{$song->lyrics}}</textarea>

      <br>
      <label for="notes">Notes</label>
      <textarea class="form-control" name="notes" type="text" id="notes">{{$song->notes}}</textarea>

      <br>
      <label for="current">In current list?</label>
      <input type="checkbox" name="current" id="current" checked=true value=1>

      <br>
      <br>
      <input class="btn btn-success btn-lg" type="submit" value="Save">
    </div>
  </form>

@stop
