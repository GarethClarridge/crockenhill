@extends('layouts/page')

@section('title'){{ $heading }}@stop

@section('meta_description'){{ $description }}@stop

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
    :image="asset('/images/headings/large/sermons.webp')"
    image-alt="Sermon Series at Crockenhill Baptist Church"
/>
<x-schema.webpage
    :heading="$heading"
    :description="$description"
    :image="asset('/images/headings/large/sermons.webp')"
/>

{{-- JSON-LD Series List --}}
@if(isset($json_ld_data))
<script type="application/ld+json">
{!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endif
@endsection

@section('dynamic_content')

@php
    $presenter = app(\App\Presenters\SermonViewPresenter::class);
@endphp

<ul class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">
  @foreach ($series as $seriesName)
    <li class="text-center p-3">
      <x-clickable-card
        heading="{{ $seriesName }}"
        link="{{ $presenter->seriesUrl((new \App\Models\Sermon(['series' => $seriesName]))) }}"
        content="" />
    </li>
  @endforeach
</ul>

@stop
