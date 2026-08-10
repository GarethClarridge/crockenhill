@extends('layouts.main')

@section('title', 'Be back soon')

@section('meta_description', 'The site is temporarily unavailable for maintenance.')

@section('content')

<main id="main-content" tabindex="-1">

    <x-h1>Be back soon</x-h1>

    <x-content-wrapper class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6 pb-16 text-center">

        {{-- $reason is supplied by a deliberate refusal (e.g. the historic
             import freeze). Laravel's own maintenance mode renders this view
             without it, which is why the generic message is the fallback. --}}
        <p class="mb-8 text-lg text-gray-600">
            {{ $reason ?? 'The site is temporarily unavailable for maintenance. Please try again shortly.' }}
        </p>

    </x-content-wrapper>

</main>

@endsection
