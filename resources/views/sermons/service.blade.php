@extends('layouts/page')

@section('title', $heading)

@section('meta_description', $description)

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
/>
@if(in_array($service, ['morning', 'evening']))
<link rel="alternate" type="application/rss+xml" title="{{ $heading }} Podcast" href="{{ route('podcast.feed', $service) }}">
@endif
@endsection

@section('full_width_content')

  <x-sermon-list :sermons="$sermons" :groupedByDate="false" />

@stop
