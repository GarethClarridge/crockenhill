@extends('layouts.layer1')

@section('title', 'Buzz Club - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Buzz Club - our yearly holiday club at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ Link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Buzz Club</li>

@stop

@section('header', '<h1>Buzz Club at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Each year we run a week of Buzz Club.  This is a holiday club for children aged 5 to 11.  A team of willing helpers from the church, along with a trained and gifted children’s worker from elsewhere, spend two hours with the children each morning singing songs, teaching them Bible stories, enjoying quizzes, doing various crafts and enjoying games.  It is always a great week, culminating on the Friday evening where parents are invited and prizes are given out.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: A week in August, 10:00 to 12:00</p>

<p>Contact: Keith Milne 01322 666246</p>

@stop
