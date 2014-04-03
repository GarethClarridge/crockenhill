@section('title')
    {{$heading}}
@stop
z
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
                    <ol class="breadcrumb">
                        <li>{{ link_to_route('Home', 'Home') }}</li>
                        {{$breadcrumbs}}
                    </ol>
                    {{$content}}
                </article>
            </div>

            <div class="col-md-3">

                @foreach ($links as $link)

                        <aside class="card">
                            @if (file_exists('images/headings/large/'.$slug.'.jpg'))
                                <div class="header-container" style="background-image: url(../images/headings/large/{{$slug}}.jpg)">
                            @else
                                <div class="header-container">
                            @endif
                                    <h3>{{$link->heading}}</h3>
                                </div>
                            <a href="/{{$link->area}}/{{$link->slug}}">{{$link->description}}</a>
                        </aside>

                @endforeach

            </div>


        </div>

    
	</main>
@stop
