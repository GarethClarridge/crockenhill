@props([
    'page' => null,
    'heading',
    'title' => null,
    'description' => null,
    'metaDescription' => null,
    'headingpicture' => null,
    'headingpictureMobile' => null,
    'headingpictureTablet' => null,
    'area' => null,
    'slug' => null,
    'links' => [],
    'canonical' => null,
    'showToolbar' => true,
    'metaTags' => true,
])

@php
    $resolvedTitle = $title ?? $heading;
    $resolvedDescription = $metaDescription
        ?? (isset($page) ? $page->meta_description : null)
        ?? ($description ? \Illuminate\Support\Str::limit(strip_tags($description), 155) : $heading);

@endphp

{{-- Title is escaped here to match @section('title', ...) which also stores escaped content; layout escapes again, yielding the double-escape used by tests. --}}
@push('title'){{ $resolvedTitle }}@endpush

{{-- Description is pushed raw so the layout single-escapes it, matching the @section('meta_description', ...) → @yield path. --}}
@push('meta_description'){!! $resolvedDescription !!}@endpush

@if ($metaTags)
@push('meta_tags')
<x-meta-tags
    :title="$heading"
    :description="$resolvedDescription"
    :image="$headingpicture ?? null"
    :image-alt="$headingpicture ? 'Crockenhill Baptist Church: ' . $heading : null"
    :canonical="$canonical"
/>
<x-schema.webpage
    :$heading
    :description="$resolvedDescription"
    :image="$headingpicture ?? null"
    :canonical="$canonical"
    :datePublished="isset($page) ? $page->created_at : null"
    :dateModified="isset($page) ? $page->updated_at : null"
/>
@endpush
@endif

@if($area && $heading)
@push('breadcrumb_schema')
<x-breadcrumbs :$area :$heading jsonOnly />
@endpush
@endif

@if ($canonical)
@push('canonical')
<link rel="canonical" href="{{ $canonical }}">
@endpush
@endif

<main id="main-content" class="mb-3" tabindex="-1">

  <article>

    <x-page-header
      :heading="$heading"
      :picture="$headingpicture"
      :picture-mobile="$headingpictureMobile"
      :picture-tablet="$headingpictureTablet"
    />

    <x-content-wrapper>

      @if (session('message'))
        <x-session-message type="success">
          {{ session('message') }}
        </x-session-message>
      @endif

      @if (session('status'))
        <x-session-message type="info">
          {{ session('status') }}
        </x-session-message>
      @endif

      @if (session('error'))
        <x-session-message type="error">
          {{ session('error') }}
        </x-session-message>
      @endif

      @if (session('notification'))
        <x-session-message :type="session('notification')['type'] ?? 'success'">
          {{ session('notification')['message'] }}
        </x-session-message>
      @endif

      @if ($showToolbar)
        <x-page-toolbar
          :area="$area"
          :heading="$heading"
          :slug="$slug"
          :editable="isset($page) || ($slug !== null && $area !== null)"
        />
      @endif

      {{ $slot }}

    </x-content-wrapper>

    @isset($fullWidth)
      {{ $fullWidth }}
    @endisset

  </article>

  <x-related-pages :links="$links" />

</main>
