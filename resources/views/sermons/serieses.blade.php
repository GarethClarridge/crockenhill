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
            :image="asset('/images/headings/large/sermons.webp')"
            image-alt="Sermon Series at Crockenhill Baptist Church"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :image="asset('/images/headings/large/sermons.webp')"
        />
        <x-breadcrumbs area="christ" :heading="$heading" jsonOnly />

        @if(isset($json_ld_data))
            <script type="application/ld+json">
                {!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
            </script>
        @endif
    @endpush

    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    <ul class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">
        @foreach ($series as $seriesName)
            <li class="text-center p-3">
                <x-clickable-card
                    heading="{{ $seriesName }}"
                    link="{{ $seriesUrls[$seriesName] }}"
                    content="" />
            </li>
        @endforeach
    </ul>
</x-page.shell>
@endsection
