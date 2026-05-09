@extends('layouts/page')

@section('title', $heading)

@section('meta_description', $description)

@section('canonical')<link rel="canonical" href="{{ $canonical_url }}">@endsection

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
