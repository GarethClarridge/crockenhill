@extends('layouts.main')

@section('title', 'Easter services')

@section('meta_description', 'Join us for our Easter services at Crockenhill Baptist Church on Good Friday and Easter Sunday in April 2026.')

@section('meta_tags')
<x-meta-tags
  title="Easter services"
  description="Join us for our Easter services on Good Friday and Easter Sunday in April 2026." />
@stop

@section('content')
<main id="main-content" class="pb-16 text-center">
  <section class="relative isolate overflow-hidden bg-cbc-pattern bg-cover text-white">
    <div class="absolute inset-0 bg-[linear-gradient(135deg,rgba(192,124,132,0.96)_0%,rgba(100,116,139,0.94)_52%,rgba(51,65,85,0.92)_100%)]"></div>

    <div class="relative px-6">
      <x-h1>
        <span class="text-white">Easter services</span>
      </x-h1>

      <x-content-wrapper class="mx-auto -mt-10 max-w-2xl px-6 pb-16 text-center">
        <p class="text-lg leading-8 text-white/90">
          Join us this Easter as we remember the death of Jesus Christ and celebrate his resurrection.
        </p>
      </x-content-wrapper>
    </div>
  </section>

  <section class="relative z-10 -mt-8 px-6 sm:-mt-10">
    <div class="mx-auto grid max-w-5xl gap-6 md:grid-cols-2">
      <x-card class="h-full rounded-2xl text-left shadow-[0_18px_45px_rgba(15,65,67,0.16)]">
        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cbc-crimson">
          Friday 3 April 2026
        </p>
        <h2 class="mt-3 font-display text-4xl text-cbc-teal-dark">
          Good Friday
        </h2>
        <p class="mt-3 text-base font-semibold text-slate-700">
          10:30AM
        </p>
        <p class="mt-4 text-base leading-7 text-slate-700">
          We're joining other churches at
          <a
            class="font-medium text-cbc-teal-dark underline decoration-cbc-teal/50 underline-offset-2 hover:text-cbc-teal-deeper"
            href="https://elmsteadbaptistchurch.org.uk/"
            target="_blank"
            rel="noopener noreferrer">
            Elmstead Baptist Church
          </a>
          to remember the death of Jesus Christ in our place.
        </p>
      </x-card>

      <x-card class="h-full rounded-2xl text-left shadow-[0_18px_45px_rgba(15,65,67,0.16)]">
        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cbc-crimson">
          Sunday 5 April 2026
        </p>
        <h2 class="mt-3 font-display text-4xl text-cbc-teal-dark">
          Easter Sunday
        </h2>
        <p class="mt-3 text-base font-semibold text-slate-700">
          10:30AM & 6:00PM
        </p>
        <p class="mt-4 text-base leading-7 text-slate-700">
          Join us at Crockenhill Baptist Church as we celebrate the resurrection of Jesus Christ.
        </p>
      </x-card>
    </div>
  </section>

  <section class="mt-12 px-6">
    <div class="mx-auto max-w-3xl rounded-2xl border border-cbc-teal/10 bg-white/90 p-6 shadow-sm">
      <p class="text-base leading-7 text-slate-700">
        We'd love to welcome you over Easter. If you'd like to get a feel for Crockenhill Baptist Church before you come, you can read more about our services below.
      </p>

      <x-public-cta
        class="mt-6 px-0"
        link="/community/sunday-mornings"
        label="What to expect on Sunday mornings"
        ariaLabel="What to expect on Sunday mornings" />
    </div>
  </section>
</main>
@stop