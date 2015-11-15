@extends('page')

@section('dynamic_content')

  @if (session('message'))
    <div class="alert alert-success" role="alert">
      {{ session('message') }}
    </div>
  @endif

  <form method="POST" action="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" accept-charset="UTF-8">
    {!! Form::token() !!}

    <div class="form-group">
      <label for="title">Title</label>
      <input class="form-control h1" id="title" name="title" type="text" value="{{$sermon->title}}">
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
      <input class="form-control" id="series" name="series" type="text" value="{{$sermon->series}}">
    </div>

    <div class="form-group">
      <label for="reference">Reference</label>
      <input class="form-control" name="reference" type="text" id="reference" value="{{$sermon->reference}}">
    </div>

    <div class="form-group">
      <label for="preacher">Preacher</label>
      <input class="form-control" id="preacher" name="preacher" type="text" value="{{$sermon->preacher}}">
    </div>

    <div class="form-actions">
      <input class="btn btn-success btn-save btn-large" type="submit" value="Save">
      <a href="{!! URL::route('members.sermons.index') !!}" class="btn btn-large">Cancel</a>
    </div>

  </form>
 
@stop
