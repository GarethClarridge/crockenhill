@extends('layouts.members')

@section('title', 'Documents - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Documents - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('Members', 'Members') }} </li><li class="active">Documents</li>

@stop

@section('header', '<h1>Documents</h1>')

@section('main-content')

<p>The minutes, agenda, etc. for the Church Meetings.</p>

@stop
