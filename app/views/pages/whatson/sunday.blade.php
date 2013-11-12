@extends('layouts.layer1')

@section('title', 'Sunday Services - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Sunday Services at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Sunday</li>

@stop

@section('header', '<h1>Sunday Services at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Our main meetings are the Sunday services, to which all are very welcome.  The services involve praying, singing and listening to a sermon based on the Bible.  During the first part of the service the children and young people are present, and there is usually a short talk given that is relevant to them.  Half-way through the service the children go through to the back rooms for more Bible teaching and are split into different groups according to their age.  Refreshments usually follow the morning service in the back hall.  Communion is held twice a month, once in the evening and once in the morning (this follows the morning service instead of refreshments and there is normally a short break between the morning service and communion).  All who know and love the Lord Jesus Christ as their saviour are invited to receive the bread and the wine.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Every Sunday, 10.30 am and 6.30 pm.</p>

<p>Contact : Laurie Everest 01322 664165</p>
@stop
