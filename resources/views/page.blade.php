@extends('layouts.main')

@section('title')
  {{$heading}}
@stop

@section('description')
  {!! $description !!}
@stop

@section('content')
  <main class="container">
    <div class="row">
      <div class="col-md-9">
        <article class="card">
          @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
            <div class="header-container" style="background-image: url({{$headingpicture}})">
          @else
            <div class="header-container">
          @endif
              <h1>
                <span>{{$heading}}</span>
              </h1>
            </div>

          <ul class="card-header-pull-right list-inline">
            @yield('social_sharing')

            @if (isset ($user))
              <li class="user-name">
                <span class="glyphicon glyphicon-user" aria-hidden="true"></span>
                {{ $user->name }}
              </li>
            @endif

          </ul>

          @if (isset ($breadcrumbs))
            <ol class="breadcrumb">
              <li>{!! link_to_route('Home', 'Home') !!}</li>
              @yield('breadcrumbs', $breadcrumbs)

              @if (isset($slug))
                @can ('edit-pages')
                  <li class="edit-buttons">
                    <form class="form-inline" action="/members/pages/{{$slug}}" method="POST">
                      <input type="hidden" name="_method" value="DELETE">
                      <input type="hidden" name="_token" value="{{ csrf_token() }}">
                      <div class="btn-group">
                        <a href="/members/pages/{{$slug}}/edit" class="btn btn-primary">
                          <span class="glyphicon glyphicon-pencil"></span> &nbsp
                          Edit
                        </a>
                        <button type="submit" class="btn btn-danger">
                          <span class="glyphicon glyphicon-trash"></span> &nbsp
                          Delete
                        </button>
                      </div>
                    </form>
                  </li>
                @endcan
              @endif
            </ol>
          @endif

          @if (session('message'))
          <div class="alert alert-success alert-dismissable" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
            <span class="glyphicon glyphicon-ok"></span> &nbsp
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
        </article>
      </div>

      <div class="col-md-3">

        @if (isset ($links))

          @foreach ($links as $link)

            @if (\Request::is('whats-on') || \Request::is('whats-on/*'))
              <aside class="card">
                @if (file_exists($_SERVER['DOCUMENT_ROOT'].'/images/headings/small/'.$link->slug.'.jpg'))
                  <div class="header-container" style="background-image: url(../images/headings/small/{{$link->slug}}.jpg)">
                @else
                  <div class="header-container">
                @endif
                    <h3><span><a href="/whats-on/{{$link->slug}}">{{$link->heading}}</a></span></h3>
                  </div>
                {{$link->description}}

                <div class="read-more"><a href="/whats-on/{{$link->slug}}">Read more ...</a></div>
              </aside>
            @else
              <aside class="card">
                @if (file_exists($_SERVER['DOCUMENT_ROOT'].'/images/headings/small/'.$link->slug.'.jpg'))
                  <div class="header-container" style="background-image: url(../images/headings/small/{{$link->slug}}.jpg)">
                @else
                  <div class="header-container">
                @endif
                    <h3><span><a href="/{{$link->area}}/{{$link->slug}}">{{$link->heading}}</a></span></h3>
                  </div>
                {{$link->description}}

                <div class="read-more"><a href="/{{$link->area}}/{{$link->slug}}">Read more ...</a></div>
              </aside>
            @endif

          @endforeach

        @endif


      </div>


    </div>


  </main>
@stop
