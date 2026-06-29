<!doctype html>
<html lang="en-GB">

<head>
  <meta charset="utf-8">

  @php
    $siteName = 'Crockenhill Baptist Church';
    $pushedTitle = trim($__env->yieldPushContent('title'));
    $sectionTitle = trim($__env->yieldContent('title'));
    $resolvedTitle = $pushedTitle !== '' ? $pushedTitle : $sectionTitle;
    $fullTitle = ($resolvedTitle === '' || $resolvedTitle === $siteName || \Illuminate\Support\Str::contains($resolvedTitle, $siteName))
      ? ($resolvedTitle ?: $siteName)
      : $resolvedTitle.' | '.$siteName;
  @endphp
  <title>{{ $fullTitle }}</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />
  <meta name="robots" content="max-image-preview:large">

  {{-- Meta Description: @push from x-page.shell takes priority; @section is the alternate path for full-width and error views. --}}
  @php $pushedDescription = trim($__env->yieldPushContent('meta_description')); @endphp
  @if($pushedDescription !== '')
    <meta name="description" content="{{ $pushedDescription }}">
  @elseif(View::hasSection('meta_description'))
    <meta name="description" content="@yield('meta_description')">
  @else
    <meta name="description" content="{{ $metaDescription ?? 'Crockenhill Baptist Church - An independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ.' }}">
  @endif

  {{-- Additional meta tags: @push from x-page.shell takes priority; @section is the alternate path for full-width and error views. --}}
  @php $pushedMetaTags = trim($__env->yieldPushContent('meta_tags')); @endphp
  @if($pushedMetaTags !== '')
    {!! $pushedMetaTags !!}
  @elseif(View::hasSection('meta_tags'))
    @yield('meta_tags')
  @endif

  {{-- BreadcrumbList JSON-LD: always on its own stack to avoid displacing the meta_tags priority logic. --}}
  @stack('breadcrumb_schema')

  {{-- Canonical URL: @push from x-page.shell takes priority; @section is the alternate path. --}}
  @php $pushedCanonical = trim($__env->yieldPushContent('canonical')); @endphp
  @if($pushedCanonical !== '')
    {!! $pushedCanonical !!}
  @elseif(View::hasSection('canonical'))
    @yield('canonical')
  @else
    <link rel="canonical" href="{{ url()->current() }}">
  @endif

  {{-- Preload hints for critical resources --}}
  <link rel="preload" as="image" href="{{ Vite::asset('resources/svg/pattern.svg') }}">
  @yield('preload')

  <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png?v=GvJNbAA7Wv">
  <link rel="icon" type="image/png" href="/favicons/favicon-32x32.png?v=GvJNbAA7Wv" sizes="32x32">
  <link rel="icon" type="image/png" href="/favicons/favicon-16x16.png?v=GvJNbAA7Wv" sizes="16x16">
  <link rel="manifest" href="/manifest.json?v=GvJNbAA7Wv">
  <link rel="mask-icon" href="/favicons/safari-pinned-tab.svg?v=GvJNbAA7Wv" color="#16324f">
  <link rel="shortcut icon" href="/favicon.ico?v=GvJNbAA7Wv">
  <meta name="theme-color" content="#16324f">

  {{-- Google Analytics 4 — bootstrapped (deferred for LCP), consent-gated, and
       pageview/event-aware in resources/js/analytics.js. The inline script only
       hands the measurement ID to JS so the module can no-op when GA is unset. --}}
  @if(config('services.google_analytics.measurement_id'))
  <script>
    window.__gaId = @json(config('services.google_analytics.measurement_id'));
  </script>
  @endif

  @vite(['resources/css/app.css', 'resources/js/app.js'])
  
  {{-- Livewire Styles --}}
  @livewireStyles

  <x-podcast-discovery />

</head>

<body class="bg-slate-200">
  {{-- Navigation Progress Bar --}}
  <div
    x-data="{ navigating: false }"
    x-on:livewire:navigating.window="navigating = true"
    x-on:livewire:navigated.window="navigating = false"
    class="fixed top-0 left-0 right-0 z-[110] h-1"
    aria-hidden="true"
  >
    <div
      x-show="navigating"
      x-transition:enter="transition ease-out duration-300"
      x-transition:enter-start="opacity-0"
      x-transition:enter-end="opacity-100"
      x-transition:leave="transition ease-in duration-500"
      x-transition:leave-start="opacity-100"
      x-transition:leave-end="opacity-0"
      class="h-full bg-cbc-teal shadow-[0_0_8px_var(--color-cbc-teal-light)] animate-progress"
      style="width: 0%;"
    ></div>
  </div>

  <div wire:offline class="fixed top-0 left-0 right-0 z-[100] bg-red-600 text-white text-center py-2 text-sm font-medium shadow-md" role="alert">
    You are currently offline. Some features may not be available.
  </div>

  <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-white focus:text-cbc-teal-dark focus:rounded-md focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-cbc-teal">
    Skip to content
  </a>

  <header x-data="{ expanded: false }" x-on:livewire:navigating.window="expanded = false" class="site-header">
    <x-layout.header />
  </header>

  @yield('content')

  <footer class="site-footer mt-6 bg-cbc-pattern bg-size-cover">
    <x-layout.footer />
  </footer>

  <x-back-to-top />

  <x-cookie-consent />

  {{-- Livewire Scripts --}}
  @livewireScripts
</body>

</html>
