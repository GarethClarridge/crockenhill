@extends('layouts.master')

@section('content')


<nav class="row">

</nav>

<div class="row">
    <article class="col-md-8">
        <ol class="breadcrumb">
            <li> {{ link_to_route('Home', 'Home') }} </li>
            @yield('breadcrumbs')
        </ol>
        @yield('header')
        @yield('main-content')
    </article>
    <aside class="col-md-3 col-md-offset-1">
        @yield('aside')
    </aside>

@stop
