@extends('layouts.main')

@section('title')
  {{$heading}}
@stop

@section('description')
  {{ $description }}
@stop

@section('content')
  <main id="online-page" class="mb-3">
    <div class="article-without-asides-head text-white">
      <div class="container mt-3">

        <div class="row justify-content-md-center">
          <div class="col-9">
            <article class="card p-0 mb-3">
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
                <div class="alert alert-success alert-dismissable fade show" role="alert">
                  <i class="far fa-check-circle"></i> &nbsp
                  {{ session('message') }}
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
                  </button>
                </div>
                @endif

                <div class="main-content text-dark">
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

      </div>
    </div>
  </main>
@stop
