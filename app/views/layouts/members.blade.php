<!-- @extends('layouts.main')

@section('content')

    <main class="container">
        <div class="row">
            
            <div class="col-md-9">
                <article class="card">
                    <div class="header-container">
                        <h1>
                            @yield('title')
                        </h1>
                    </div>
                    <ol class="breadcrumb">
                        <li>{{ link_to_route('Home', 'Home') }}</li>
                        <li>{{ link_to('members', 'Members')}}</li>
                        <li class="active">
                            @yield('title')
                        </li>
                    </ol>        
                    @yield('membercontent')
                    
                </article>
            </div>

            <div class="col-md-3">
                @foreach ($links as $link)

                    @if ($link->slug != 'homepage')

                        <aside class="card">
                            @if (file_exists('images/headings/small/'.$link->slug.'.jpg'))
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

                @include('includes.membersadminnav');


            </div>
        </div>
    </main>
        
@stop
 -->