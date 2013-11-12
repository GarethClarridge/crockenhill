@extends('layouts.layer1')

@section('title', 'Carols in the Chequers - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Carols in the Chequers - organised by Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ Link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Carols in the Chequers</li>

@stop

@section('header', '<h1>Carols in the Chequers at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Just before Christmas, at the invitation of the Landlord, the church leads a session of carol singing in The Chequers pub, which is well appreciated by the customers.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: An evening in December</p>

@stop
