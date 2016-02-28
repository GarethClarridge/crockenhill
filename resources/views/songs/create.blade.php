@extends('page')

@section('dynamic_content')

  <form method="POST" action="/members/songs" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    <div class="form-group">
      {!! Form::token() !!}

      {{ Form::label('title', 'Title') }}
      {{ Form::text('title', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('alternative-title', 'Alternative Title') }}
      <small><i>Optional</i></small>
      {{ Form::text('alternative-title', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('author', 'Author') }}
      <small><i>of the lyrics, may not be the composer of the music.</i></small>
      {{ Form::text('author', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('copyright', 'Copyright') }}
      <small><i>to the lyrics, may not be the same as the music.</i></small>
      {{ Form::text('copyright', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::label('lyrics', 'Lyrics') }}
      <br>
      <small><b>Do not upload lyrics unless the song is out of copyright.</b></small>
      {{ Form::textarea('lyrics', null, array('class' => 'form-control')) }}

      <br>
      {{ Form::submit('Save', array('class' => 'btn btn-primary btn-lg btn-block')) }}
    </div>
  {{ Form::close() }}

@stop