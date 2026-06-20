@extends('layouts.main')

@section('title', 'Page not found')

@section('meta_description', 'Sorry, that page doesn\'t seem to exist.')

@section('content')

<main id="main-content" tabindex="-1">

    <x-h1>Page not found</x-h1>

    <x-content-wrapper class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6 pb-16 text-center">

        <p class="mb-8 text-lg text-gray-600">
            Sorry, that page doesn't seem to exist. It may have been moved or removed.
        </p>

        <div class="mx-auto w-full max-w-xs">
            <div class="w-full rounded-xl bg-gradient-teal p-[1.5px]">
                <x-button link="/" variant="featureOutline" size="lg" class="w-full rounded-[11px]">
                    Return to the homepage
                </x-button>
            </div>
        </div>

    </x-content-wrapper>

</main>

@endsection
