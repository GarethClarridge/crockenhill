@extends('layouts.page')

@section('dynamic_content')
    <section class="space-y-8">
        <div class="overflow-hidden rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-8 shadow-sm">
            <div class="space-y-4 text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cbc-teal-dark/75">For families and young listeners</p>
                <div class="space-y-3">
                    <p class="mx-auto max-w-2xl font-sans text-lg text-gray-700">
                        A simple place to find our recent children's talks, with quick access to audio and video whenever they are available.
                    </p>
                </div>
                <div class="mx-auto w-full max-w-[34rem] px-2 text-center">
                    <div class="w-full rounded-xl bg-[linear-gradient(120deg,theme(colors.cbc-teal.light)_0%,theme(colors.cbc-teal.DEFAULT)_55%,theme(colors.cbc-teal.dark)_100%)] p-[1.5px]">
                        <x-button link="/christ/sermons" variant="secondary" size="lg" class="w-full rounded-[11px]">
                            Browse full sermon library
                        </x-button>
                    </div>
                </div>
            </div>
        </div>

        @if ($talks->isEmpty())
            <x-card heading="No talks published yet">
                <p>We have not published any children's talks yet. Please check back soon.</p>
            </x-card>
        @endif
    </section>
@endsection

@section('full_width_content')
    @if ($talks->isNotEmpty())
        <section class="px-6 pb-10 pt-2">
            <div class="mx-auto grid max-w-2xl grid-cols-1 gap-6 sm:max-w-5xl sm:grid-cols-2 xl:max-w-7xl xl:grid-cols-3">
                @foreach ($talks as $talk)
                    <x-childrens-talk-card :sermon="$talk" />
                @endforeach
            </div>

            @if ($talks->hasPages())
                <div class="mx-auto mt-8 max-w-2xl">
                    {{ $talks->links() }}
                </div>
            @endif
        </section>
    @endif
@endsection
