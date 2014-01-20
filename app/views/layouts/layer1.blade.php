@extends('layouts.master')

@section('content')

<div class="row">
    <div class="col-md-9">
        <article class="card">
            <div class="header-container">
                <h1>@yield('header')</h1>
            </div>
            <ol class="breadcrumb">
                <li> {{ link_to_route('Home', 'Home') }} </li>
                @yield('breadcrumbs')
            </ol>
            @yield('main-content')
        </article>
    </div>
    @yield('aside')

@stop
