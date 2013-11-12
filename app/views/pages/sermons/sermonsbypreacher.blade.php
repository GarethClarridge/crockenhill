@extends('layouts.layer1')

@section('title')
Sermons from {{ $preacher }} - Crockenhill Baptist Church
@stop

@section('description', '<meta name="description" content="Recorded sermons - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('Sermons', 'Sermons') }} </li><li class="active"> {{ $preacher }}</li>

@stop

@section('header')
<h1>Sermons by {{ $preacher }}</h1>
@stop

@section('main-content')
<div class="row">
    <section class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h2 class="panel-title">Mornings</h2>
            </div>
            <ul class="list-group">
                @foreach ($morning_sermons as $morning)
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
                                {{ $morning->preacher }}
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
    </section>

    <section class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Evenings</h3>
            </div>
            <ul class="list-group">
                @foreach ($evening_sermons as $evening)
                    <li class="list-group-item">
                        <h3>
                            {{ link_to_route('sermon', '"'.$evening->title.'"', $parameters = array($evening->filename))}}
                        </h3>
                        <div class="sermon-details">
                            <p>
                                <span class="glyphicon glyphicon-calendar"></span> &nbsp
                                {{ date ('jS \of F', strtotime($evening->date)) }}
                            </p>
                            <p>
                                <span class="glyphicon glyphicon-user"></span> &nbsp
                                {{ $evening->preacher }}
                            </p>
                            <p>
                                <span class="glyphicon glyphicon-book"></span> &nbsp
                                {{ $evening->reference }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
</div>

@stop

@section('aside')

@include('layouts.sections.sermonsfragment')

@stop
