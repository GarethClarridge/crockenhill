@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :description="$description ?? null"
    :metaDescription="$metaDescription ?? $description"
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$heading"
            :description="$description"
            :image="asset('/images/homepage/may2024wide.webp')"
            image-alt="Crockenhill Baptist Church members outside the church building"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :image="asset('/images/homepage/may2024wide.webp')"
        />

        @if (isset($json_ld_data))
            <script type="application/ld+json">
                {!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
            </script>
        @endif
    @endpush

    <section class="space-y-8">
        <div class="overflow-hidden rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-8 shadow-sm">
            <div class="space-y-4 text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cbc-teal-dark/75">For families and young listeners</p>
                <div class="space-y-3">
                    <p class="mx-auto max-w-2xl font-sans text-lg text-gray-700">
                        A simple place to find our recent children's talks, with quick access to audio and video whenever they are available.
                    </p>
                </div>
                <x-public-cta link="/christ/sermons" label="Browse full sermon library" />
            </div>
        </div>

        @if ($talks->isEmpty())
            <x-card heading="No talks published yet">
                <p>We have not published any children's talks yet. Please check back soon.</p>
            </x-card>
        @endif
    </section>

    <x-slot:fullWidth>
        @if ($talks->isNotEmpty())
            <section class="px-6 pb-10 pt-2">
                <div class="mx-auto grid max-w-2xl grid-cols-1 gap-6 sm:max-w-5xl sm:grid-cols-2 xl:max-w-7xl xl:grid-cols-3">
                    @foreach ($talks as $talk)
                        <x-childrens-talk-card :sermon="$talk" :sermonView="$presentedTalks[$talk->id] ?? null" />
                    @endforeach
                </div>

                @if ($talks->hasPages())
                    <nav class="mx-auto mt-8 max-w-2xl" aria-label="Pagination">
                        {{ $talks->links() }}
                    </nav>
                @endif
            </section>
        @endif
    </x-slot:fullWidth>
</x-page.shell>
@endsection
