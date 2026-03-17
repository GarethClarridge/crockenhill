@extends('layouts/page')

@section('title'){{ $heading }}@stop

@section('meta_description'){{ $description }}@stop

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
/>
@endsection

@section('full_width_content')

  {{-- JSON-LD Sermon List --}}
  @if(isset($json_ld_data))
  <script type="application/ld+json">
  {!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
  </script>
  @endif

  <x-sermon-list :sermons="$sermons" :groupedByDate="false" />

@stop
