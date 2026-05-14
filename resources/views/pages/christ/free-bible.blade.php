@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :description="$description ?? null"
    :metaDescription="$metaDescription ?? null"
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
>
    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    <div class="mt-8">
        <livewire:christ.bible-request-form />
    </div>
</x-page.shell>
@endsection
