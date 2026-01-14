@extends('layouts.main')

@section('title')
{{$heading}}
@stop

@section('meta_description')
{{ isset($page) ? $page->meta_description : (isset($description) ? \Illuminate\Support\Str::limit(strip_tags($description), 155) : $heading) }}
@stop

@section('meta_tags')
{{-- Open Graph meta tags --}}
<meta property="og:title" content="{{ $heading }} - Crockenhill Baptist Church">
<meta property="og:description" content="{{ isset($page) ? $page->meta_description : (isset($description) ? \Illuminate\Support\Str::limit(strip_tags($description), 155) : $heading) }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:site_name" content="Crockenhill Baptist Church">
<meta property="og:image" content="{{ asset('images/Primary.png') }}">
<meta property="og:image:width" content="800">
<meta property="og:image:height" content="600">

{{-- Twitter Card meta tags --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $heading }} - Crockenhill Baptist Church">
<meta name="twitter:description" content="{{ isset($page) ? $page->meta_description : (isset($description) ? \Illuminate\Support\Str::limit(strip_tags($description), 155) : $heading) }}">
<meta name="twitter:image" content="{{ asset('images/Primary.png') }}">
@stop

@section('content')
<main class="mb-3">

  <article>

    @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
    <x-h1-picture :headingpicture="$headingpicture">
      {{$heading}}
    </x-h1-picture>
    @else
    <x-h1>
      {{$heading}}
    </x-h1>
    @endif

    <div class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">

      @if (session('message'))
      <x-session-message>
        {{ session('message') }}
      </x-session-message>
      @endif

      <div class="inline-flex items-center justify-between w-full flex-wrap">
        <x-breadcrumbs :$area :$heading />

        @if(isset($page) || (isset($slug) && isset($area) && isset($content) && !empty($content))) <x-edit-buttons :$slug /> @endif
      </div>

      @if (isset ($content))
      <div class="mt-6 prose lg:prose-xl">
        {!! $content !!}
      </div>
      @endif

      @yield('dynamic_content')

    </div>

    @yield('full_width_content')

  </article>

  @if (isset ($links))
  <x-h2>
    Related pages
  </x-h2>
  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 justify-center max-w-2xl lg:max-w-5xl xl:max-w-7xl mx-auto mt-6">
    @foreach ($links as $link)
    <x-page-card :page="$link" />
    @endforeach
  </div>
  @endif

</main>
@stop