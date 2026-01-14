<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">

  <title>@yield('title') - Crockenhill Baptist Church</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />

  {{-- Meta Description --}}
  @hasSection('meta_description')
    <meta name="description" content="@yield('meta_description')">
  @else
    <meta name="description" content="{{ $metaDescription ?? 'Crockenhill Baptist Church - An independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ.' }}">
  @endif

  {{-- Canonical URL --}}
  <link rel="canonical" href="{{ url()->current() }}">

  {{-- Additional meta tags for social media sharing --}}
  @yield('meta_tags')

  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=GvJNbAA7Wv">
  <link rel="icon" type="image/png" href="/favicon-32x32.png?v=GvJNbAA7Wv" sizes="32x32">
  <link rel="icon" type="image/png" href="/favicon-16x16.png?v=GvJNbAA7Wv" sizes="16x16">
  <link rel="manifest" href="/manifest.json?v=GvJNbAA7Wv">
  <link rel="mask-icon" href="/safari-pinned-tab.svg?v=GvJNbAA7Wv" color="#16324f">
  <link rel="shortcut icon" href="/favicon.ico?v=GvJNbAA7Wv">
  <meta name="theme-color" content="#16324f">

  {{-- Google Analytics 4 --}}
  @if(config('services.google_analytics.measurement_id'))
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google_analytics.measurement_id') }}"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '{{ config('services.google_analytics.measurement_id') }}');
  </script>
  @endif

  @vite(['resources/css/app.scss', 'resources/js/app.js'])
  
  {{-- Livewire Styles --}}
  @livewireStyles

</head>

<body class="bg-slate-200">
  <header x-data="{ expanded: false }">
    <x-layout.header />
  </header>

  @yield('content')

  <footer class="bg-cbc-pattern bg-cover p-6 mt-6">
    <x-layout.footer />
  </footer>

  {{-- Livewire Scripts --}}
  @livewireScripts
</body>

</html>