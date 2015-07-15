@section('title')
    {{$heading}}
@stop

@section('description')
    {{$description}}
@stop

@section('content')
	<main class="container">
	    <div class="row">
            <div class="col-md-9">
                <article class="card">
                    @if (file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
                        <div class="header-container" style="background-image: url({{$headingpicture}})">
                    @else
                        <div class="header-container">
                    @endif
                    
                        <h1>{{$heading}}</h1>
                                
                        </div>
                    
                    @yield('social_sharing')   
                        
                    <ol class="breadcrumb">
                        <li>{{ link_to_route('Home', 'Home') }}</li>
                        @yield('breadcrumbs', $breadcrumbs)
                    </ol>
         
                    {{$content}}
                    
                    @yield('dynamic_content')
                </article>
            </div>

            <div class="col-md-3">

                @foreach ($links as $link)

                    @if (Request::is('whats-on') || Request::is('whats-on/*'))
                        <aside class="card">
                            @if (file_exists($_SERVER['DOCUMENT_ROOT'].'/images/headings/small/'.$link->slug.'.jpg'))
                                <div class="header-container" style="background-image: url(../images/headings/small/{{$link->slug}}.jpg)">
                            @else
                                <div class="header-container">
                            @endif
                                    <h3><a href="/whats-on/{{$link->slug}}">{{$link->heading}}</a></h3>
                                </div>
                            {{$link->description}}

                            <div class="read-more"><a href="/{{$link->area}}/{{$link->slug}}">Read more ...</a></div>
                        </aside>

                    @elseif ($link->slug != 'homepage')
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

                @include('includes.membersadminnav')


            </div>


        </div>

    
	</main>
@stop
