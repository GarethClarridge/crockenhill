@extends('layouts.layer1')

@section('title', 'CAMEO - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="CAMEO - a meeting at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ Link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">CAMEO</li>

@stop

@section('header', '<h1>CAMEO at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>'Come and meet each other' – a weekly meeting, with interesting speakers.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Thursdays, 2:00PM</p>

<p>Contact: Sheila Weaver 01322 663574</p>

@stop
