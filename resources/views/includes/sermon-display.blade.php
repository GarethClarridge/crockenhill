<div class="card">
  <div class="card-body text-center">
    <div class="card-title">
      <h3>
        <a href="/church/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a>
      </h3>
    </div>
    <ul class="list-group list-group-flush">
      @if (($sermon->date != null))
        <li class="list-group-item">
          <i class="far fa-calendar"></i>
          &nbsp; {{ date('j F Y', strtotime($sermon->date)) }}
        </li>
      @endif
      @if ($sermon->service != null)
        <li class="list-group-item">
          <i class="far fa-clock"></i>
          &nbsp; {{ \Illuminate\Support\Str::title($sermon->service) }}
        </li>
      @endif
      @if ($sermon->preacher != null)
        <li class="list-group-item">
          <i class="far fa-user"></i> &nbsp
          <a href="/church/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
        </li>
      @endif
      @if ($sermon->series != null)
        <li class="list-group-item">
          <i class="fas fa-tag"></i> &nbsp
          <a href="/church/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
        </li>
      @endif
      @if ($sermon->reference != null)
        <li class="list-group-item">
          <i class="fas fa-book"></i> &nbsp
          {{ $sermon->reference }}
        </li>
      @endif
    </ul>
    @can ('edit-sermons')
      <form method="POST" action="/church/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8" class="pt-3">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <div class="btn-group">
          <a href="/church/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="btn btn-primary">
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
