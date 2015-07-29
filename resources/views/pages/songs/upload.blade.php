@extends('pages.page')

@section('dynamic_content')

  {{ Form::open(array('url' => '/members/songs/upload')) }}
    <div class="form-group">
      {{ Form::label('title', 'Title') }}
      {{ Form::text('title', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('alternative-title', 'Alternative Title') }}
      <small><i>Optional</i></small>
      {{ Form::text('alternative-title', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('author', 'Author') }}
      <small><i>Of the lyrics, not the composer of the music.</i></small>
      {{ Form::text('author', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('copyright', 'Copyright') }}
      <small><i>To the lyrics, not the music.</i></small>
      {{ Form::text('copyright', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('lyrics', 'Lyrics') }}
      <br>
      <small><b>Warning: Do not upload lyrics unless the song is out of copyright.</b></small>
      {{ Form::textarea('lyrics', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::submit('Save', array('class' => 'btn btn-primary btn-lg btn-block')) }}
    </div>
  {{ Form::close() }}

@stop