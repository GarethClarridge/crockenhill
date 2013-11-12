@extends('layouts.layer1')

@section('title', '404 - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="404 - Crockenhill Baptist Church">')

@section('breadcrumbs', '<li class="active">Ooops!</li>')

@section('header', '<h1>Ooops!</h1>')

@section('main-content')

<p>We're sorry, we don't seem to be able to find that page. If you typed in the address, please check for spelling mistakes. If you came here from a link on the site, please report it to us using {{HTML::mailto('website@crockenhill.org', 'website@crockenhill.org')}} and we'll try and fix it as soon as possible.</p>

@stop
