@extends('layouts/page')

@section('title', $heading)

@section('meta_description', $description)

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
/>
@endsection

@section('full_width_content')

  <x-sermon-list :sermons="$sermons" :groupedByDate="true" />

@stop
