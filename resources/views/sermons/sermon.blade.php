@extends('layouts/page')

@section('dynamic_content')

  @if (($sermon->points != null))
    <h2>Sermon outline</h2>

    <section class="sermon-headings">
      {!!trim($sermon->points)!!}
    </section>
  @endif

  <section class="space-y-6">
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
        <a href="/christ/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
      </p>
    @endif
    @if ($sermon->series != null)
      <p>
        <i class="fas fa-tag"></i> &nbsp
        <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
      </p>
    @endif
    @if ($sermon->reference != null)
      <p>
        <i class="fas fa-book"></i> &nbsp
        {{ $sermon->reference }}
      </p>
    @endif

    <audio 
      src="/media/sermons/{{$sermon->filename}}.mp3" 
      class="my-6 w-full"
      controls>
      Your browser does not support the <code>audio</code> element.
    </audio>

    <x-button link="/media/sermons/{{$sermon->filename}}.mp3">
      <i class="me-2 fas fa-download"></i>
      Download this sermon
    </x-button>
    
  </section>

  @can ('edit-sermons')
      <form method="POST" 
              action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" 
              accept-charset="UTF-8" 
              class="my-6 grid grid-cols-2">
          <input type="hidden" 
                  name="_token" 
                  value="{{ csrf_token() }}">
          <a href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit"
              class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-l-md bg-gradient-to-r from-cyan-500 to-blue-500 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
              Edit
              <i class="ms-2 fas fa-pencil-alt"></i>
          </a>

          <button type="submit" class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-r-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
              Delete
              <i class="ms-2 fas fa-trash"></i>
          </button>
      </form> 
  @endcan

  <small class="prose">
    If there are any problems with this sermon, please email
    <a href="mailto:sermons@crockenhill.org">sermons@crockenhill.org</a>.
  </small>

@stop
