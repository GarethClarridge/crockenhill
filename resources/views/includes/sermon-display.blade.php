<h3>
  <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a>
</h3> 
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
@if ($sermon->reference != null)
  <p>
    <span class="glyphicon glyphicon-book"></span> &nbsp
    {{ $sermon->reference }}
  </p>
@endif
@if ($user != null && $user->email == "admin@crockenhill.org")
  <form method="POST" action="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8">
    {!! Form::token() !!}
    <div class="btn-group">
      <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="btn btn-primary">
        Edit this sermon
      </a>
      
      <button type="submit" class="btn btn-danger">
        Delete this sermon
      </button>
    </div>
  </form>
@endif