@extends('layouts.main')

@section('title', 'Access denied')

@section('meta_description', 'You do not have permission to access that page.')

@section('content')

<main id="main-content" tabindex="-1">

    <x-h1>Access denied</x-h1>

    <x-content-wrapper class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6 pb-16 text-center">

        <p class="mb-8 text-lg text-gray-600">
            Sorry, your account doesn't have the right permissions to access that page.
            To get access please talk to Gareth.
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
