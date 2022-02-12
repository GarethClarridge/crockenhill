@extends('layouts.main')

@section('title')
  {{$heading}}
@stop

@section('description')
  {{ $description }}
@stop

@section('content')
  <main class="container mb-3">
    <div class="row">
      <div class="col-md-12">
        <article class="card p-0 mt-3">

          <div class="card-img-caption d-flex align-items-center">
            <h1 class="card-text text-white">
              <div class="px-2 py-1">
                {{$heading}}
              </div>
            </h1>
            @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
              <img class="card-img-top cbc-card-img-top" src="{{$headingpicture}}">
            @else
              <img class="card-img-top cbc-card-img-top" src="/images/headings/large/default.jpg">
            @endif
          </div>

          <div class="card-body">

            @if (session('message'))
            <div class="alert alert-success alert-dismissable" role="alert">
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
              <i class="far fa-check-circle"></i> &nbsp
              {{ session('message') }}
            </div>
            @endif

            <div class="main-content">
              @if (isset ($content))
                {!! $content !!}
                <div class="clearfix">

                </div>
              @endif
            </div>

          @yield('dynamic_content')

          @if (isset($slug))
            @can ('edit-pages')
              <hr>

              <form class="form-inline" action="/church/members/pages/{{$slug}}" method="POST">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <div class="btn-group">
                  <a href="/church/members/pages/{{$slug}}/edit" class="btn btn-primary">
                    <i class="fas fa-pencil-alt"></i> &nbsp
                    Edit page
                  </a>
                  <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> &nbsp
                    Delete page
                  </button>
                </div>
              </form>
            @endcan
          @endif
        </div>
      </article>
    </div>
  </div>

    @if (isset ($links))

      <h3 class="my-3">Related pages</h3>

      <div class="row g-2 d-flex flex-nowrap overflow-scroll">

      @foreach ($links as $link)

        @if (\Request::is('community') || \Request::is('community/*'))
          <aside class="card p-0 m-2">
            <div class="card-img-caption d-flex align-items-center">
              <h4 class="card-text text-white">
                <div class="p-1">
                  {{$link->heading}}
                </div>
              </h4>
              @if (file_exists($_SERVER['DOCUMENT_ROOT'].'/images/headings/small/'.$link->slug.'.jpg'))
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/{{$link->slug}}.jpg">
              @else
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
              @endif
            </div>

            <div class="card-body">
                {{$link->description}}
                <div class="read-more">
                  <a href="/community/{{$link->slug}}">Read more ...</a>
                </div>
              </div>
          </aside>
        @elseif (\Request::is('church') || \Request::is('church/*'))
          <aside class="card p-0 m-2">
            <div class="card-img-caption d-flex align-items-center">
              <h4 class="card-text text-white">
                <div class="p-1">
                  {{$link->heading}}
                </div>
              </h4>
              @if (file_exists($_SERVER['DOCUMENT_ROOT'].'/images/headings/small/'.$link->slug.'.jpg'))
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/{{$link->slug}}.jpg">
              @else
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
              @endif
            </div>

            <div class="card-body">
              {{$link->description}}
              <div class="read-more">
                <a href="/church/{{$link->slug}}">Read more ...</a>
              </div>
            </div>
          </aside>
        @else
          <aside class="card p-0 m-2">
            <div class="card-img-caption d-flex align-items-center">
              <h4 class="card-text text-white">
                <div class="p-1">
                  {{$link->heading}}
                </div>
              </h4>
              @if (file_exists($_SERVER['DOCUMENT_ROOT'].'/images/headings/small/'.$link->slug.'.jpg'))
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/{{$link->slug}}.jpg">
              @else
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
              @endif
            </div>

            <div class="card-body">
              {{$link->description}}
              <div class="read-more">
                <a href="/{{$link->area}}/{{$link->slug}}">Read more ...</a>
              </div>
            </div>
          </aside>
        @endif

        @endforeach

      </div>

      @endif

  </main>
@stop
