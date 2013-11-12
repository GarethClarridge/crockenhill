@extends('layouts.layer1')

@section('title', 'Baby Talk - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Baby Talk - a group for those with babies and toddlers at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Baby Talk</li>

@stop

@section('header', '<h1>Baby Talk at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>Baby Talk is for those with babies and toddlers. It’ s a great place to come and chat with others mums, dads and carers in a safe and friendly environment whilst watching the children play . Refreshments are served during the morning.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Wednesdays in term time, 9:15AM</p>

<p>Contact : Vicky - 01689 821338, or Laura - 01322 614884</p>
@stop
