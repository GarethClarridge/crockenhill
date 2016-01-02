@extends('page')

@section('dynamic_content')

  @if (session('message'))
    <div class="alert alert-success" role="alert">
      {{ session('message') }}
    </div>
  @endif

  @if ($user != null && $user->email == "admin@crockenhill.org")
      <a href="/sermons/create" class="btn btn-primary btn-lg btn-block" role="button">Upload a new sermon</a>
  @endif

  @foreach ($latest_sermons as $date => $sermon)
    <section class="week">
      <div class="row">
        <div class="col-md-2">
          <h2 class="panel panel-primary">
            <p class="panel-heading text-center">{{ date('M', strtotime($date)) }}</p>
            <p class="panel-body text-center">{{ date('j', strtotime($date)) }}</p>
          </h2>
        </div>
        @foreach ($sermon as $sermon)
          <div class="col-md-5">
            <h3>Morning</h3>
            @if ($sermon->service === "morning")
              <h4 class="h3">
                <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a>
              </h4> 
              <p>
                <span class="glyphicon glyphicon-user"></span> &nbsp
                <a href="/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
              </p>
              <p>
                <span class="glyphicon glyphicon-book"></span> &nbsp
                {{ $sermon->reference }}
              </p>
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
            @else
              <p>Sorry, no morning sermon is available for this week.</p>
              <p>This is most likely due to a technical error.</p>
            @endif
          </div>

          <div class="col-md-5">
            <h3>Evening</h3>
            @if ($sermon->service === "evening")
              <h4 class="h3">
                <a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a>
              </h4> 
              <p>
                <span class="glyphicon glyphicon-user"></span> &nbsp
                <a href="/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
              </p>
              <p>
                <span class="glyphicon glyphicon-book"></span> &nbsp
                {{ $sermon->reference }}
              </p>
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
            @else
              <p>Sorry, no evening sermon is available for this week.</p>
              <p>We might have been at another church for a joint service,
                  or there could have been a technical error.</p>
            @endif
          </div>
        @endforeach
        <br>
      </div>
    </section>
  @endforeach
  <br>
  <a href="/sermons/all" class="btn btn-primary btn-lg btn-block" role="button">Find older sermons</a>

@stop