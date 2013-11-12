@extends('layouts.layer1')

@section('title', 'Link - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Link - our club for teenagers at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Link</li>

@stop

@section('header', '<h1>Link at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Link is for teenagers. Games and activities are organised, but there is also the opportunity to sit and chill out and enjoy what's on offer at the tuck shop. Thought provoking talks are given from the Bible.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Fridays in term time, 6:30 - 8:00PM</p>

<p>Contact : Ivan Kimble 01322 663356</p>
@stop
