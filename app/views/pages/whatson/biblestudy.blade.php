@extends('layouts.layer1')

@section('title', 'Bible Study - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Bible Study - our weekly small group Bible studies at Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('WhatsOn', 'What\'s On') }} </li><li class="active">Bible Study</li>

@stop

@section('header', '<h1>Bible Study at Crockenhill Baptist Church</h1>')

@section('main-content')

<p>On a Sunday our pastor teaches us from the Bible at the front of the church.  However, during the week small Bible study groups are held so that we can meet informally for fellowship, interactive Bible study and prayer.  Three of these are run in people’s homes and one runs at the church.  The material is prepared by the Pastor and Church Leaders and we all work through the same material.  All the groups are run by capable leaders and such times provide the opportunity to ask questions.  At present, two groups run on a Tuesday evening and two on a Thursday evening.  Everyone is welcome to join one of these groups.  Please contact our pastor for further details.</p>

@stop

@section('aside')

<h3>More Information</h3>

<p>When: Throughout the weekly</p>

<p>Contact: Mark Drury, 01322 663995</p>

@stop
