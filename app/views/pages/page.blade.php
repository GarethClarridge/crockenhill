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
                    <div class="header-container" style="background-image: url(../images/headings/{{$slug}}.jpg)">
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
                            <div class="header-container" style="background-image: url(../images/headings/{{$link->slug}}.jpg)">
                                <h3>{{$link->heading}}</h3>
                            </div>
                            {{$link->description}}
                        </aside>

                @endforeach

            </div>


        </div>

    
	</main>
@stop
