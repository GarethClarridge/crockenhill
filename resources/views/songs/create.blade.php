@extends('page')

@section('dynamic_content')

  <form method="POST" action="/members/songs" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    <div class="form-group">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">

      <br>
      <label for="title">Title</label>
      <input class="form-control" name="description" type="text" id="title">

      <br>
      <label for="alternative-title">Alternative Title</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="description" type="text" id="alternative-title">

      <br>
      <label for="major-category">Praise! category</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="description" type="text" id="major-category">

      <br>
      <label for="minor-category">Praise! subcategory</label>
      <small><i>Optional</i></small>
      <input class="form-control" name="description" type="text" id="minor-category">

      <br>
      <label for="author">Author</label>
      <small><i>of the lyrics, may not be the composer of the music.</i></small>
      <input class="form-control" name="description" type="text" id="author">

      <br>
      <label for="copyright">Copyright</label>
      <small><i>to the lyrics, may not be the same as the music.</i></small>
      <input class="form-control" name="description" type="text" id="copyright">

      <br>
      <label for="lyrics">Lyrics</label>
      <small><b>Do not upload lyrics unless the song is out of copyright.</b></small>
      <textarea class="form-control" name="lyrics" id="lyrics"></textarea>

      <br>
      <label for="notes">Notes</label>
      <textarea class="form-control" name="notes" id="notes"></textarea>

      <br>
      <label for="current">In current list?</label>
      <input type="checkbox" name="current" checked>

      <br>
      <br>
      <input class="btn btn-success btn-lg" type="submit" value="Save">
    </div>
  </form>

@stop
