@extends('layouts/page')

@section('title', $heading)

@section('meta_description', $description)

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
/>
<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="{{ route('podcast.feed', 'morning') }}">
<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="{{ route('podcast.feed', 'evening') }}">
@endsection

@section('dynamic_content')
@can('manage-sermons')
<div class="px-6 max-w-2xl mx-auto mt-6 my-12">
  <x-button link="{{ route('admin.sermon-upload.create') }}">
    Upload a new sermon
  </x-button>
</div>
@endcan
@stop

@section('full_width_content')

<x-sermon-list :sermons="$latest_sermons" :groupedByDate="true" />

<div class="mt-8">
  <x-public-cta
    link="/christ/sermons/all"
    label="Find older sermons"
    ariaLabel="Find older sermons"
  />
</div>


@stop
