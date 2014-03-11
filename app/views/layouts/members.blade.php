@extends('layouts.main')

@section('content')

    <main class="container-fluid">
        <div class="row">
            <div class="col-md-2">
                @include('includes.membersnav')
            </div>
            
            <div class="col-md-8">
                <article class="card">
                    <div class="header-container"">
                        <h1>
                            @yield('title')
                        </h1>
                    </div>
                    <ol class="breadcrumb">
                        <li>{{ link_to_route('Home', 'Home') }}</li>
                        <li>{{-- link_to_route('Members', 'Members') --}}</li>
                        <li class="active">
                            @yield('title')
                        </li>
                    </ol>        
                    @yield('membercontent')
                    
                </article>
            </div>
        </div>
    </main>
        
@stop
