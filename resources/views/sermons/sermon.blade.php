@extends('page')

@section('dynamic_content')

  <h2>Sermon outline</h2>
  <section class="sermon-headings">
    {!!trim($sermon->points)!!}
  </section>

  <section class="sermon-details">
    @if (($sermon->date != null))
      <p>
        <i class="far fa-calendar"></i>
        &nbsp; {{ date('j F Y', strtotime($sermon->date)) }}
      </p>
    @endif
    @if ($sermon->service != null)
      <p>
        <i class="far fa-clock"></i>
        &nbsp; {{ \Illuminate\Support\Str::title($sermon->service) }}
      </p>
    @endif
    @if ($sermon->preacher != null)
      <p>
        <i class="far fa-user"></i> &nbsp
        <a href="/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
      </p>
    @endif
    @if ($sermon->series != null)
      <p>
        <i class="fas fa-tag"></i> &nbsp
        <a href="/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
      </p>
    @endif
    @if ($sermon->reference != null)
      <p>
        <i class="fas fa-book"></i> &nbsp
        {{ $sermon->reference }}
      </p>
    @endif
    <p>
      <i class="fas fa-download"></i>  &nbsp
      <a href="/media/sermons/{{$sermon->filename}}.mp3">
        Download this sermon
      </a>
    </p>
    <audio src="/media/sermons/{{$sermon->filename}}.mp3" controls>
      Your browser does not support the <code>audio</code> element.
    </audio>
  </section>

  <hr>
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
    <br>
  @endcan
  <small>If there are any problems with this sermon, please email
    <a href="mailto:sermons@crockenhill.org">sermons@crockenhill.org</a>.
  </small>

@stop
