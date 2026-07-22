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
    :canonical="$canonical_url"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$heading"
            :description="$description"
            :canonical="$canonical_url"
            :image="asset('/images/headings/large/sermons.webp')"
            image-alt="Sermons at Crockenhill Baptist Church"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :image="asset('/images/headings/large/sermons.webp')"
            :canonical="$canonical_url"
        />
    @endpush

    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    @if (auth()->user()?->canAccessAdmin())
        <div class="px-6 max-w-2xl mx-auto mt-6 my-12">
            <x-button
                link="{{ (bool) config('service-tracking.enabled', true) ? route('admin.services.add', ['intent' => 'recording']) : route('admin.services.upload-recording') }}"
                aria-label="Upload a new sermon recording"
            >
                Upload a new sermon
            </x-button>
        </div>
    @endif

    <x-slot:fullWidth>
        <livewire:sermons.browse-sermons />
    </x-slot:fullWidth>
</x-page.shell>
@endsection
