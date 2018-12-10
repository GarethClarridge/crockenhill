<div class="card">
  <div class="card-body">
    <div class="card-title">
      <h3>
        <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a>
      </h3>
    </div>
    @if (($sermon->date != null))
      <p class="card-text">
        <i class="far fa-calendar"></i>
        &nbsp; {{ date('j F Y', strtotime($sermon->date)) }}
      </p>
    @endif
    @if ($sermon->service != null)
      <p class="card-text">
        <i class="far fa-clock"></i>
        &nbsp; {{ \Illuminate\Support\Str::title($sermon->service) }}
      </p>
    @endif
    @if ($sermon->preacher != null)
      <p class="card-text">
        <i class="far fa-user"></i> &nbsp
        <a href="/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
      </p>
    @endif
    @if ($sermon->series != null)
      <p class="card-text">
        <i class="fas fa-tag"></i> &nbsp
        <a href="/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
      </p>
    @endif
    @if ($sermon->reference != null)
      <p class="card-text">
        <i class="fas fa-book"></i> &nbsp
        {{ $sermon->reference }}
      </p>
    @endif
    @can ('edit-sermons')
      <form method="POST" action="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <div class="btn-group">
          <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="btn btn-primary">
            <i class="fas fa-pencil-alt"></i> &nbsp
            Edit
          </a>

          <button type="submit" class="btn btn-danger">
            <i class="fas fa-trash"></i> &nbsp
            Delete
          </button>
        </div>
      </form>
    @endcan
  </div>
</div>
