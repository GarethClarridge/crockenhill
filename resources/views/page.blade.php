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
          
            <h1>{{$heading}}</h1>
                
            </div>
          
          @yield('social_sharing')   
          
          @if (isset ($breadcrumbs))
            <ol class="breadcrumb">
              <li>{!! link_to_route('Home', 'Home') !!}</li>
              @yield('breadcrumbs', $breadcrumbs)
              @if (isset ($admin))
                @if ($user != null && $user->email == "admin@crockenhill.org")
                  <li><a href="{{ $admin }}/edit" class="btn btn-primary">
                    Edit this page
                  </a></li>
                @endif
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
                    <h3><a href="/whats-on/{{$link->slug}}">{{$link->heading}}</a></h3>
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
                    <h3><a href="/{{$link->area}}/{{$link->slug}}">{{$link->heading}}</a></h3>
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
