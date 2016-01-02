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

  @foreach ($latest_sermons as $date => $sermons)
    <section class="week">
      <div class="row">
        <div class="col-sm-2">
          <h2 class="panel panel-primary">
            <p class="panel-heading text-center">{{ date('M \'y', strtotime($date)) }}</p>
            <p class="panel-body text-center">{{ date('j', strtotime($date)) }}</p>
          </h2>
        </div>
        @if (count($sermons) != 2)
          @foreach ($sermons as $sermon)
            <div class="col-sm-5">
              @if ($sermon->service === "morning")
                @include('includes.sermon-display')
              @else
                <br>
                <p>Sorry, no morning sermon is available for this week.</p>
                <p>This is most likely due to a technical error.</p>
              @endif
            </div>

            <div class="col-sm-5">
              @if ($sermon->service === "evening")
                @include('includes.sermon-display')
              @else
                <br>
                <p>Sorry, no evening sermon is available for this week.</p>
                <p>We might have been at another church for a joint service,
                    or there could have been a technical error.</p>
              @endif
            </div>
          @endforeach
        @else
          @foreach ($sermons as $sermon)
            <div class="col-sm-5">
              @include('includes.sermon-display')
            </div>            
          @endforeach
        @endif
        <br>
      </div>
    </section>
  @endforeach
  <br>
  <a href="/sermons/all" class="btn btn-primary btn-lg btn-block" role="button">Find older sermons</a>

@stop