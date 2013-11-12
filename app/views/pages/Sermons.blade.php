@extends('layouts.layer1')

@section('title', 'Crockenhill Baptist Church - About Us')

@section('description', '<meta name="description" content="Recent sermons preached at Crockenhill Baptist Church.">')

@section('breadcrumbs', '<li class="active">Sermons</li>')

@section('header', '<h1>Recent Sermons</h1>')

@section('main-content')

<p>The sermons in the morning and evening services are recorded every week. They appear here as soon as they can be edited and uploaded. This page shows the most recent 10 sermons from the morning and evening services. More sermons can be found via the menu. Click on your choice of sermon to listen to it.</p>

<p>Not every sermon is stored on the website due to space limitations. If you can't find the sermon you're looking for, then please send an email to recordings@crockenhill.org, and we'll try and find it for you.</p>

<br>

<div class="row">
    <section class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h2 class="panel-title">Mornings</h2>
            </div>
            <ul class="list-group">
                @foreach ($mornings as $morning)
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
                @foreach ($evenings as $evening)
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
