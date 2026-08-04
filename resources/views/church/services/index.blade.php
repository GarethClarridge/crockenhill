@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :description="$description"
    :meta-description="$description"
    :area="$area"
    :slug="$slug"
    :links="$links"
    :canonical="$canonical_url"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$heading"
            :description="$description"
            :canonical="$canonical_url"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
            :canonical="$canonical_url"
        />
    @endpush

    <section class="space-y-8">
        <div class="rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-6 shadow-sm sm:p-8">
            <p class="max-w-3xl text-lg text-gray-700">
                Find a service by year, then open its public order to follow the sermon, children's talk, scripture and songs that are available.
            </p>

            <div class="mt-6 space-y-4" aria-label="Service archive filters">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="mr-2 text-sm font-semibold uppercase tracking-wide text-cbc-teal-dark">Year</span>
                    <x-button
                        :link="route('church.services.index', array_filter(['service' => $selectedService?->value]))"
                        :variant="$selectedYear === null ? 'feature' : 'featureOutline'"
                        size="sm"
                        :aria-current="$selectedYear === null ? 'page' : null"
                    >
                        All years
                    </x-button>
                    @foreach ($years as $year)
                        <x-button
                            :link="route('church.services.index', array_filter(['year' => $year, 'service' => $selectedService?->value]))"
                            :variant="$selectedYear === $year ? 'feature' : 'featureOutline'"
                            size="sm"
                            :aria-current="$selectedYear === $year ? 'page' : null"
                        >
                            {{ $year }}
                        </x-button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="mr-2 text-sm font-semibold uppercase tracking-wide text-cbc-teal-dark">Service</span>
                    <x-button
                        :link="route('church.services.index', array_filter(['year' => $selectedYear]))"
                        :variant="$selectedService === null ? 'feature' : 'featureOutline'"
                        size="sm"
                        :aria-current="$selectedService === null ? 'page' : null"
                    >
                        All services
                    </x-button>
                    @foreach (\App\Enums\SermonService::cases() as $service)
                        <x-button
                            :link="route('church.services.index', array_filter(['year' => $selectedYear, 'service' => $service->value]))"
                            :variant="$selectedService === $service ? 'feature' : 'featureOutline'"
                            size="sm"
                            :aria-current="$selectedService === $service ? 'page' : null"
                        >
                            {{ $service->label() }}
                        </x-button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($services as $serviceRecord)
                <x-card>
                    <div class="flex h-full flex-col gap-5">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cbc-teal-dark/75">
                                {{ $serviceRecord->service->label() }} service
                            </p>
                            <h2 class="mt-2 font-display text-3xl text-gray-900">
                                <time datetime="{{ $serviceRecord->date->toDateString() }}">
                                    {{ $serviceRecord->date->format('j F Y') }}
                                </time>
                            </h2>
                        </div>
                        <p class="flex-1 text-gray-600">View the publication-safe service history and available media.</p>
                        <x-button
                            :link="route('church.services.show', ['date' => $serviceRecord->date->format('Y-m-d'), 'service' => $serviceRecord->service->value])"
                            variant="feature"
                            size="card"
                            icon="arrow-right-circle"
                            icon-style="solid"
                            icon-position="trailing"
                            icon-class="shrink-0 text-white/90"
                            class="w-full justify-between"
                        >
                            View service
                        </x-button>
                    </div>
                </x-card>
            @empty
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-card heading="No services found">
                        <p>There are no public services matching these filters yet.</p>
                    </x-card>
                </div>
            @endforelse
        </div>

        @if ($services->hasPages())
            <nav class="flex flex-wrap justify-center gap-3" aria-label="Service archive pages">
                @if ($services->previousPageUrl())
                    <x-button :link="$services->previousPageUrl()" variant="outline" size="md">Previous</x-button>
                @endif
                @if ($services->nextPageUrl())
                    <x-button :link="$services->nextPageUrl()" variant="outline" size="md">Next</x-button>
                @endif
            </nav>
        @endif
    </section>
</x-page.shell>
@endsection
