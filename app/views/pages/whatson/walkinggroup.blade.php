@extends('layouts.layer1')

@section('title', 'Walking Group - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Walking Group - a group fromCrockenhill Baptist Church who meet for walks">')

@section('breadcrumbs')

<li> {{ Link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Walking Group</li>

@stop

@section('header', '<h1>Walking Group at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Leisurely morning walks in the local countryside, not exclusively for senior citizens.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Occasional Friday mornings, meeting at 9.15 am</p>

<p>Contact: Laurie Everest 01322 664165</p>

@stop
