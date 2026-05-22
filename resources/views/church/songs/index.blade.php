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
    :canonical="$canonical_url ?? null"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$heading"
            :description="$description"
            :canonical="$canonical_url ?? null"
            :image="asset('/images/homepage/may2024wide.webp')"
            image-alt="Worship songs at Crockenhill Baptist Church"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :canonical="$canonical_url ?? null"
        />
        <x-breadcrumbs :$area :$heading jsonOnly />
    @endpush

    <livewire:church.songs.browse-songs />
</x-page.shell>
@endsection
