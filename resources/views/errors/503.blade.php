@extends('layouts.main')

@section('title', 'Be back soon')

@section('meta_description', 'The site is temporarily unavailable for maintenance.')

@section('content')

<main id="main-content" tabindex="-1">

    <x-h1>Be back soon</x-h1>

    <x-content-wrapper class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6 pb-16 text-center">

        <p class="mb-8 text-lg text-gray-600">
            The site is temporarily unavailable for maintenance. Please try again shortly.
        </p>

    </x-content-wrapper>

</main>

@endsection
