@extends('layouts/page')

@section('title'){{ $heading }}@stop

@php
    $bio = $preacher->bio ? trim(strip_tags($preacher->bio)) : null;
    $metaDescription = $bio ? \Illuminate\Support\Str::limit($bio, 155) : $description;
@endphp

@section('meta_description'){{ $metaDescription }}@stop

@section('meta_tags')
@php
    $imageUrl = $preacher->image_path
        ? (\Illuminate\Support\Str::startsWith($preacher->image_path, ['http://', 'https://', '/'])
            ? $preacher->image_path
            : \Illuminate\Support\Facades\Storage::disk('public')->url($preacher->image_path))
        : null;
@endphp
<x-meta-tags
    :title="$heading"
    :description="$metaDescription"
    :image="$imageUrl"
/>

<x-schema.person :$preacher />

{{-- JSON-LD Sermon List --}}
<script type="application/ld+json">
{!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endsection

@section('full_width_content')

  <x-sermon-list :sermons="$sermons" :groupedByDate="false" />


@stop
