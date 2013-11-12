@extends('layouts.layer1')

@section('title')
Sermons from {{ $series }} - Crockenhill Baptist Church
@stop

@section('description', '<meta name="description" content="Recorded sermons - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('Sermons', 'Sermons') }} </li><li class="active"> {{ $series }}</li>

@stop

@section('header')
<h1>{{ $series }}</h1>
@stop

@section('main-content')

<div class="panel panel-default">
    <ul class="list-group">
        @foreach ($sermons as $morning)
            <li class="list-group-item">
                <h3>
                    {{ link_to_route('sermon', '"'.$morning->title.'"', $parameters = array($morning->filename))}}
                </h3>
                <div class="sermon-details">
                    <p>
                        <span class="glyphicon glyphicon-calendar"></span> &nbsp
                        {{ date ('jS \of F', strtotime($morning->date)) }}
                    </p>
                    <p>
                        <span class="glyphicon glyphicon-user"></span> &nbsp
                        {{ $morning->series }}
                    </p>
                    <p>
                        <span class="glyphicon glyphicon-book"></span> &nbsp
                        {{ $morning->reference }}
                    </p>
                </div>
            </li>
        @endforeach
    </ul>
</div>

@stop

@section('aside')

@include('layouts.sections.sermonsfragment')

@stop
