@extends('layouts/page')

@section('dynamic_content')

  @if (session('message'))
    <div class="alert alert-success" role="alert">
      {{ session('message') }}
    </div>
  @endif

  <form method="POST" action="/church/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" accept-charset="UTF-8">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <div class="mb-3">
      <label for="title">Title</label>
      <input class="form-control h1" id="title" name="title" type="text" value="{{$sermon->title}}">
    </div>

    <div class="mb-3">
      <label for="date">Date</label>
      @if (date('D') === 'Sun')
        <input type="date" class="form-control" id="date" name="date" value="{!!date('Y-m-d')!!}">
      @else
        <input type="date" class="form-control" id="date" name="date" value="{!!date('Y-m-d',strtotime('last sunday'))!!}">
      @endif
    </div>

    <div class="mb-3">
      <label for="service">Service</label>
      @if ($sermon->service == 'morning')
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

    <div class="mb-3">
      <label for="series">Series</label>
      <input class="form-control" id="series" name="series" type="text" value="{{$sermon->series}}">
    </div>

    <div class="mb-3">
      <label for="reference">Reference</label>
      <input class="form-control" name="reference" type="text" id="reference" value="{{$sermon->reference}}">
    </div>

    <div class="mb-3">
      <label for="preacher">Preacher</label>
      <input class="form-control" id="preacher" name="preacher" type="text" value="{{$sermon->preacher}}">
    </div>

    <div class="mb-3">
      <label for="points">Sermon headings</label>
      <textarea class="form-control" rows="5" name="points">
        {{$sermon->points}}
      </textarea>
    </div>

    <div class="form-actions">
      <input class="btn btn-success btn-save btn-large" type="submit" value="Save">
      <a href="/sermons" class="btn btn-large">Cancel</a>
    </div>

  </form>

@stop
