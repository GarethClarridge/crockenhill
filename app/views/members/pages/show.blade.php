@extends('layouts.members')

@section('title', 'Website Pages')

@section('description', '<meta name="description" content="A list of website pages">')

@section('membercontent')

    <h2>Display page</h2>

	<hr>

	<h3>{{ $page->title }}</h3>
	<h5>{{ $page->created_at }}</h5>
	{{ $page->body }}
	
@stop
