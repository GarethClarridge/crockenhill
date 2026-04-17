@extends('layouts/page')

@section('title', $heading)

@section('meta_description', $description)

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
    :canonical="$canonical_url"
    :image="asset('/images/headings/large/sermons.webp')"
    image-alt="Sermons at Crockenhill Baptist Church"
/>
<x-schema.webpage
    :heading="$heading"
    :description="$description"
    :image="asset('/images/headings/large/sermons.webp')"
/>
<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="{{ route('podcast.feed', 'morning') }}">
<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="{{ route('podcast.feed', 'evening') }}">

{{-- JSON-LD Sermon List --}}
<script type="application/ld+json">
{!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endsection

@section('dynamic_content')
@if (auth()->user()?->canAccessAdmin())
<div class="px-6 max-w-2xl mx-auto mt-6 my-12">
  <x-button link="{{ route('admin.sermon-upload.create') }}">
    Upload a new sermon
  </x-button>
</div>
@endif
@stop

@section('full_width_content')
<livewire:sermons.browse-sermons />

@stop
