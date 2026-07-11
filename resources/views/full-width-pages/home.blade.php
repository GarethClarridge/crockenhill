@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church | Worshipping God, Strengthening believers, Proclaiming Jesus Christ')

@section('meta_description', 'We are an independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ to all.')

@section('canonical')
<link rel="canonical" href="{{ url('/') }}">
@endsection

@section('meta_tags')
<x-meta-tags
  title="Crockenhill Baptist Church"
  description="We are an independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ to all."
  :image="asset('/images/homepage/may2024wide.webp')"
  image-alt="Crockenhill Baptist Church members outside the church building" />
<x-schema.webpage
  heading="Crockenhill Baptist Church"
  description="We are an independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ to all."
  :image="asset('/images/homepage/may2024wide.webp')"
  :include-breadcrumb="false"
/>
<x-schema.website />
<x-schema.organization />
@endsection

@section('preload')
<link rel="preload" as="image" href="{{ asset('/images/homepage/may2024wide.webp') }}" media="(min-width: 768px)">
<link rel="preload" as="image" href="{{ asset('/images/homepage/may2024mobile-portrait-600.webp') }}" media="(max-width: 767px)">
@endsection

@section('content')

<main id="main-content" tabindex="-1" class="text-sm -mt-px text-center">

  {{-- Hero --}}
  <div class="home-hero">
    {{-- Background Image (LCP element) --}}
    <picture class="absolute inset-0 block">
      <source
        media="(max-width: 767px)"
        srcset="{{ asset('/images/homepage/may2024mobile-portrait-600.webp') }} 600w,
                {{ asset('/images/homepage/may2024mobile-portrait.webp') }} 675w"
        sizes="100vw">
      <source
        media="(min-width: 768px)"
        srcset="{{ asset('/images/homepage/may2024wide.webp') }}">
      <img
        src="{{ asset('/images/homepage/may2024wide.webp') }}"
        alt="Crockenhill Baptist Church members outside the church building"
        class="h-full w-full object-cover md:object-right"
        width="1200"
        height="450"
        fetchpriority="high">
    </picture>
    {{-- Gradient Overlay --}}
    <div class="absolute inset-0 bg-gradient-to-tr from-black/70 via-black/40 to-black/10 md:from-black/65 md:via-black/35"></div>
    {{-- Content --}}
    <div class="relative mx-auto grid grid-cols-1 justify-items-center md:grid-cols-2">
      <h1 class="home-hero-title home-typewriter">
        <span class="home-typewriter-line home-typewriter-line-1">Crockenhill</span><br>
        <span class="home-typewriter-line home-typewriter-line-2">Baptist</span><br>
        <span class="home-typewriter-line home-typewriter-line-3">Church.</span>
      </h1>
      <div class="home-hero-nav">
        <div class="home-hero-nav-link home-hero-nav-link-1">
          <x-hero-nav-link href="#worshipping-god" text="Worshipping God" />
        </div>
        <div class="home-hero-nav-link home-hero-nav-link-2">
          <x-hero-nav-link href="#strengthening-believers" text="Strengthening believers" />
        </div>
        <div class="home-hero-nav-link home-hero-nav-link-3">
          <x-hero-nav-link href="#proclaiming-jesus-christ-to-all" text="Proclaiming Jesus Christ to all" />
        </div>
      </div>
    </div>
  </div>

  <x-h2>
    Welcome
  </x-h2>

  <x-text>
    <p>
      Crockenhill Baptist Church is a friendly, Bible-teaching church in the village of Crockenhill,
      just outside Swanley and on the road between St Mary Cray and Eynsford.
    </p>
  </x-text>

  <x-h2>
    Worshipping God
  </x-h2>

  <x-text>
    <p>
      We meet to worship God together as a church every Sunday at 10:30am.
      Services involve reading the Bible, praying, singing
      and hearing God's word preached. We also meet on Sunday evenings at
      6:00pm for a service mostly focussed on prayer.
    </p>
    <p>
      You're more than welcome to join us - we'd love to see you! If you can't make it in person you can watch our morning services on
      <a class="inline" href="{{ config('organization.social.youtube') }}">
        our YouTube channel
      </a> at 10:30am on Sundays.
    </p>
  </x-text>

  <x-public-cta
    class="mb-8 mt-8 sm:px-0"
    link="/community/sunday-mornings"
    label="What to expect on Sunday mornings"
    ariaLabel="What to expect on Sunday mornings" />


  <x-h2>
    Strengthening believers
  </x-h2>

  <x-text>
    <p>
      We want to continue to grow in our faith: becoming closer to God
      as the Holy Spirit makes us more like Jesus.
    </p>
  </x-text>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 gap-6 justify-center max-w-2xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'sunday-evenings')" />

    <x-page-card :page="$pages->firstWhere('slug', 'bible-study')" />

  </div>


  <x-h2>
    Proclaiming Jesus Christ to all
  </x-h2>

  <x-text>
    <p>
      We're eager to tell people about Jesus.
      After all, Christianity is good news!
    </p>
  </x-text>


  <x-public-cta
    class="mb-8 mt-6 sm:px-0"
    link="/christ"
    label="Explore the good news about Jesus"
    ariaLabel="Explore the good news about Jesus" />

  <x-text>
    <p class="mb-4 mt-2">
      If you've got questions, do get in touch using the details below.
      We'd love to hear from you.
    </p>
  </x-text>

</main>

@stop
