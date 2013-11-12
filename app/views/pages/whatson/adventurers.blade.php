@extends('layouts.layer1')

@section('title', 'Adventurers - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Adventurers - a club for 6 to 9 year olds at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Adventurers</li>

@stop

@section('header', '<h1>Adventurers at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Adventurers is for those aged 6 to 9. It is an evening packed with games, craft and Bible stories. For most of the year it is held at the church, but during the summer the youngsters are taken up to the Cricket Meadow for outdoor games.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Wednesdays in term time, 6:00 - 7:15PM</p>

<p>Contact : Simon Perry 01322 665932</p>
@stop
