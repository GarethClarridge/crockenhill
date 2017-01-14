@extends('page')

@section('social_sharing')
  <!-- <li>
    <a href="http://www.facebook.com/crockenhill">
      <i class="fa fa-facebook-square"></i>
    </a>
  </li>

  <li>
    <a href="http://www.twitter.com/crockenhill">
      <i class="fa fa-twitter-square"></i>
    </a>
  </li>

  <li>
    <a href="http://www.facebook.com/crockenhill">
      <i class="fa fa-google-plus-square"></i>
    </a>
  </li> -->
@stop

@section('dynamic_content')

  @if (session('message'))
    <div class="alert alert-success" role="alert">
      {{ session('message') }}
    </div>
  @endif

  @can ('edit-sermons')
    <form class="edit-buttons" method="POST" action="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">
      <div class="btn-group">
        <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="btn btn-primary">
          Edit
        </a>

        <button type="submit" class="btn btn-danger">
          Delete
        </button>
      </div>
    </form>
  @endcan

  <section class="sermon-details">
    @if (($sermon->date != null))
      <p>
        <span class="glyphicon glyphicon-calendar"></span>
        &nbsp; {{ date('j F Y', strtotime($sermon->date)) }}
      </p>
    @endif
    @if ($sermon->service != null)
      <p>
        <span class="glyphicon glyphicon-time"></span>
        &nbsp; {{ \Illuminate\Support\Str::title($sermon->service) }}
      </p>
    @endif
    @if ($sermon->preacher != null)
      <p>
        <span class="glyphicon glyphicon-user"></span> &nbsp
        <a href="/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
      </p>
    @endif
    @if ($sermon->series != null)
      <p>
        <span class="glyphicon glyphicon-tag"></span> &nbsp
        <a href="/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
      </p>
    @endif
    @if ($sermon->reference != null)
      <p>
        <span class="glyphicon glyphicon-book"></span> &nbsp
        {{ $sermon->reference }}
      </p>
    @endif
    <audio src="/media/sermons/{{$sermon->filename}}.mp3" controls>
      Your browser does not support the <code>audio</code> element.
    </audio>
  </section>

  <section class="sermon-headings">
    {!!$sermon->points!!}
  </section>

  <hr>
  <small>If there are any problems with this sermon, please email
    <a href="mailto:sermons@crockenhill.org">sermons@crockenhill.org</a>.
  </small>

@stop
