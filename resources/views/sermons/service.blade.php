@extends('layouts/page')

@section('title'){{ $heading }}@stop

@section('meta_description'){{ $description }}@stop

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
/>
<x-schema.webpage
    :heading="$heading"
    :description="$description"
/>
@if($service === 'morning' || $service === 'other')
<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="{{ route('podcast.feed', 'morning') }}">
@endif
@if($service === 'evening' || $service === 'other')
<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="{{ route('podcast.feed', 'evening') }}">
@endif

{{-- JSON-LD Sermon List --}}
<script type="application/ld+json">
{!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endsection

@section('full_width_content')

  <x-sermon-list :sermons="$sermons" :groupedByDate="false" />

@stop
