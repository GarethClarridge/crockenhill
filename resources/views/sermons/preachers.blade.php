@extends('layouts/page')

@section('title', $heading)

@section('meta_description', $description)

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$description"
/>
<x-breadcrumbs area="christ" heading="Preachers" :jsonOnly="true" />

{{-- JSON-LD Preachers List --}}
@if(isset($json_ld_data))
<script type="application/ld+json">
{!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endif
@endsection

@section('dynamic_content')

  <ul class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    @foreach ($preachers as $preacher)
      <li class="flex justify-center">
        <x-clickable-card
            :heading="$preacher->name"
            link="preachers/{{ $preacher->slug }}"
            class="w-full">
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-dark text-white">
            {{ $preacher->sermons_count }} {{ Str::plural('sermon', $preacher->sermons_count) }}
          </span>
        </x-clickable-card>
      </li>
    @endforeach
  </ul>


@stop
