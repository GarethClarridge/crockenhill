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
            image-alt="Sermons at Crockenhill Baptist Church"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :image="asset('/images/headings/large/sermons.webp')"
        />
        <x-breadcrumbs area="christ" :heading="$heading" jsonOnly />

        <script type="application/ld+json">
            {!! json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
        </script>
    @endpush

    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    <x-slot:fullWidth>
        <x-sermon-list :sermons="$sermons" :presentedSermons="$presentedSermons" :groupedByDate="false" />
    </x-slot:fullWidth>
</x-page.shell>
@endsection
