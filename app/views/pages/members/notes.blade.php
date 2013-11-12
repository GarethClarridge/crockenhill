@extends('layouts.members')

@section('title', 'Bible Study notes - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Bible Study notes - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('Members', 'Members') }} </li><li class="active">Bible Study notes</li>

@stop

@section('header', '<h1>Bible Study Notes</h1>')

@section('main-content')

<p>The latest Bible Study notes will be available online here.</p>

@stop
