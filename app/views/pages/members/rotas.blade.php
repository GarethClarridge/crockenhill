@extends('layouts.members')

@section('title', 'Rotas - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Rotas - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('Members', 'Members') }} </li><li class="active">Rotas</li>

@stop

@section('header', '<h1>Rotas</h1>')

@section('main-content')

<p>All the church rotas. If the rota you want isn't here, then please ask the person responsible for it to give the rota to Keith Milne, and he'll upload it.</p>

@stop
