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
                    @if (file_exists('images/headings/large/'.$slug.'.jpg'))
                        <div class="header-container" style="background-image: url(../images/headings/large/{{$slug}}.jpg)">
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

                        <aside class="card">
                            @if (file_exists('images/headings/large/'.$link->slug.'.jpg'))
                                <div class="header-container" style="background-image: url(../images/headings/large/{{$link->slug}}.jpg)">
                            @else
                                <div class="header-container">
                            @endif
                                    <h3><a href="/{{$link->area}}/{{$link->slug}}">{{$link->heading}}</a></h3>
                                </div>
                            {{$link->description}}

                            <div class="read-more"><a href="/{{$link->area}}/{{$link->slug}}">Read more ...</a></div>

                        </aside>

                @endforeach

                @if (Auth::check() && Auth::getUser()->hasRole('Admin') &&  Request::is('members/*'))

                    <aside class="card">
                        
                        <div class="header-container">
                            <h3><a href="pages">Pages</a></h3>
                        </div>
                        Create and edit the pages of the website.
                        <div class="read-more"><a href="pages">Read more ...</a></div>

                    </aside>

                    <aside class="card">
                        
                        <div class="header-container">
                            <h3><a href="sermons">Sermons</a></h3>
                        </div>
                        Upload new sermons and edit old ones.
                        <div class="read-more"><a href="sermons">Read more ...</a></div>

                    </aside>
                @endif


            </div>


        </div>

    
	</main>
@stop
