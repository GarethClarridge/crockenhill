@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :meta-description="$metaDescription ?? null"
    :description="$description ?? null"
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
</x-page.shell>
@endsection
