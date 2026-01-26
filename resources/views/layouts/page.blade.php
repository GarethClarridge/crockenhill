@extends('layouts.main')

@section('title')
{{$heading}}
@stop

@section('meta_description')
{{ isset($page) ? $page->meta_description : (isset($description) ? \Illuminate\Support\Str::limit(strip_tags($description), 155) : $heading) }}
@stop

@section('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="isset($page) ? $page->meta_description : (isset($description) ? \Illuminate\Support\Str::limit(strip_tags($description), 155) : $heading)"
/>
@stop

@section('content')
<main class="mb-3">

  <article>

    {{-- Page Header --}}
    <x-page-header
      :heading="$heading"
      :picture="$headingpicture ?? null"
      :picture-mobile="$headingpictureMobile ?? null"
      :picture-tablet="$headingpictureTablet ?? null"
    />

    <x-content-wrapper>

      {{-- Session Messages --}}
      @if (session('message'))
        <x-session-message>
          {{ session('message') }}
        </x-session-message>
      @endif

      {{-- Toolbar: Breadcrumbs + Edit Buttons --}}
      <x-page-toolbar
        :area="$area ?? null"
        :heading="$heading"
        :slug="$slug ?? null"
        :editable="isset($page) || (isset($slug) && isset($area) && isset($content) && !empty($content))"
      />

      {{-- Main Content --}}
      @if (isset ($content))
        <div class="mt-6 prose lg:prose-xl">
          {!! $content !!}
        </div>
      @endif

      {{-- Dynamic Content Slot --}}
      @yield('dynamic_content')

    </x-content-wrapper>

    {{-- Full Width Content Slot --}}
    @yield('full_width_content')

  </article>

  {{-- Related Pages --}}
  <x-related-pages :links="$links ?? []" />

</main>
@stop