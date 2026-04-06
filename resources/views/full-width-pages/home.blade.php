@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('meta_description', 'We are an independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ to all.')

@section('meta_tags')
<x-meta-tags
  title="Crockenhill Baptist Church"
  description="We are an independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, proclaiming Jesus Christ to all."
  :image="asset('/images/homepage/may2024wide.webp')" />
<x-schema.organization />
@endsection

@section('preload')
<link rel="preload" as="image" href="{{ asset('/images/homepage/may2024wide.webp') }}" media="(min-width: 768px)">
<link rel="preload" as="image" href="{{ asset('/images/homepage/may2024mobile-portrait-600.webp') }}" media="(max-width: 767px)">
@endsection

@section('content')

<main id="main-content" class="text-sm -mt-px text-center">

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
        alt=""
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

  <section aria-labelledby="easter-services-promo" class="relative isolate -mt-16 overflow-hidden bg-cbc-pattern bg-cover py-12 text-white sm:py-16">
    <div class="absolute inset-0 bg-[linear-gradient(135deg,rgba(192,124,132,0.96)_0%,rgba(100,116,139,0.94)_52%,rgba(51,65,85,0.92)_100%)]"></div>

    <div class="relative mx-auto max-w-6xl px-6">
      <p class="text-center text-xs font-semibold uppercase tracking-[0.35em] text-white/75 sm:text-sm">
        Easter 2026
      </p>

      <h2 id="easter-services-promo" class="mx-auto mt-4 max-w-5xl text-center font-display text-4xl text-white sm:text-5xl">
        He is risen!
      </h2>

      <x-content-wrapper class="mx-auto mt-5 max-w-2xl px-6 text-center">
        <p class="text-lg leading-8 text-white/90">
          Join us over Easter as we remember the death of Jesus Christ and celebrate his resurrection.
        </p>
      </x-content-wrapper>

      <div class="mx-auto mt-10 grid max-w-5xl gap-6 md:grid-cols-2">
        <x-card class="h-full rounded-2xl border-white/10 bg-white/95 text-left shadow-[0_22px_50px_rgba(0,0,0,0.18)]">
          <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cbc-crimson">
            Friday 3 April 2026
          </p>
          <h3 class="mt-3 font-display text-3xl text-cbc-teal-dark sm:text-4xl">
            Good Friday
          </h3>
          <p class="mt-3 text-base font-semibold text-slate-700">
            10:30AM
          </p>
          <p class="mt-4 text-base leading-7 text-slate-700">
            At <a
              class="font-medium text-cbc-teal-dark underline decoration-cbc-teal/50 underline-offset-2 hover:text-cbc-teal-deeper"
              href="https://elmsteadbaptistchurch.org.uk/"
              target="_blank"
              rel="noopener noreferrer">
              Elmstead Baptist Church
            </a> with other local churches.
          </p>
        </x-card>

        <x-card class="h-full rounded-2xl border-white/10 bg-white/95 text-left shadow-[0_22px_50px_rgba(0,0,0,0.18)]">
          <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cbc-crimson">
            Sunday 5 April 2026
          </p>
          <h3 class="mt-3 font-display text-3xl text-cbc-teal-dark sm:text-4xl">
            Easter Sunday
          </h3>
          <p class="mt-3 text-base font-semibold text-slate-700">
            10:30AM & 6:00PM
          </p>
          <p class="mt-4 text-base leading-7 text-slate-700">
            Come and celebrate the resurrection of Jesus Christ.
          </p>
        </x-card>
      </div>

      <x-public-cta
        class="mt-10 sm:px-0"
        :link="route('easter')"
        label="See the Easter service details"
        ariaLabel="See the Easter service details" />
    </div>
  </section>

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
      6pm for a service mostly focussed on prayer.
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

  <!-- <x-youtube 
      link="https://www.youtube.com/embed?listType=playlist&list=UUtSUTtkZlALToswWQpWS2kA" 
      title=""
    /> -->

  <!-- <section class="-mb-10 bg-cover bg-center bg-[url('/public/images/homepage/christmas2023.webp')] bg-gray-700 bg-blend-multiply">
    <div class="my-10 px-4 mx-auto max-w-screen-xl text-center pt-24 pb-12">
      <h2 class="mb-20 text-4xl font-display leading-none text-white md:text-5xl lg:text-6xl">
        Christmas at Crockenhill Baptist Church
      </h2>
      <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
        Preparing Room
      </h3>
      <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
        Saturday 30th November, 3-6pm
      </p>
      <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
        Coffee Cup Carols
      </h3>
      <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
        Thursday 12th, 10:30am
      </p>
      <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
        Carols in the Chequers
      </h3>
      <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
        Wednesday 18th, 7:30pm
      </p>
      <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
        Carols by Candlelight
      </h3>
      <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
        Sunday 22nd, 6:00pm
      </p>
      <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
        Christmas Morning Service
      </h3>
      <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
        Wednesday 25th, 10:30am
      </p>
    </div>
  </section> -->

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

  <!-- <x-youtube link="https://www.youtube.com/embed/Ue3rHGDMzjU" title="Good News in 90 Seconds" /> -->

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