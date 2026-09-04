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
            image-alt="Preachers at Crockenhill Baptist Church"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :image="asset('/images/headings/large/sermons.webp')"
        />

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

    <ul class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach ($preachers as $preacher)
            <li class="flex justify-center">
                <x-clickable-card
                    :heading="$preacher->name"
                    :link="route('sermons.preacher', $preacher->slug, false)"
                    class="w-full">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cbc-teal-dark text-white">
                        {{ $preacher->sermons_count }} {{ \Illuminate\Support\Str::plural('sermon', $preacher->sermons_count) }}
                    </span>
                </x-clickable-card>
            </li>
        @endforeach
    </ul>
</x-page.shell>
@endsection
