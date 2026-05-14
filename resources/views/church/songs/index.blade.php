@extends('layouts.main')

@section('content')
<x-page.shell
    heading="Songs"
    description="Browse the songs most often sung at Crockenhill Baptist Church."
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
>
    <livewire:church.songs.browse-songs />
</x-page.shell>
@endsection
