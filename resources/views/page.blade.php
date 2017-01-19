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
              @if (isset ($edit_url))
                @can ('edit-pages')
                  <li>
                    <a href="{{ $edit_url }}/edit" class="btn btn-primary">
                      <span class="glyphicon glyphicon-pencil"></span> &nbsp
                      Edit page
                    </a>
                  </li>
                  <li>
                    <form class="form-inline" action="/members/pages/{{$edit_url}}" method="POST">
                      <input type="hidden" name="_method" value="DELETE">
                      <input type="hidden" name="_token" value="{{ csrf_token() }}">
                      <button type="submit" class="btn btn-danger">
                        <span class="glyphicon glyphicon-trash"></span> &nbsp
                        Delete page
                      </button>
                    </form>
                  </li>
                @endcan
              @endif
            </ol>
          @endif

          @if (isset ($content))
            {!! $content !!}
          @endif

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
