@extends('layouts/admin')

@section('dynamic_content')

@php
    $selectedService = $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->value : $sermon->service;
@endphp

<x-admin.form-shell
    title="Edit Sermon"
    description="Update sermon details and metadata."
>
    <x-slot:actions>
        <x-button link="/sermons" variant="outline" inline>Cancel</x-button>
        <x-form-button variant="primary" form="sermon-edit-form" icon="check">Save</x-form-button>
    </x-slot:actions>

    <x-card heading="Sermon Details">
        <form id="sermon-edit-form" method="POST" action="/christ/sermons/{{ date('Y', strtotime($sermon->date)) }}/{{ date('m', strtotime($sermon->date)) }}/{{ $sermon->slug }}/edit" accept-charset="UTF-8" class="space-y-4">
            @csrf

            <x-input label="Title" id="title" name="title" type="text" value="{{ $sermon->title }}" />

            <x-input label="Date" id="date" name="date" type="date" value="{{ $sermon->date ? $sermon->date->format('Y-m-d') : '' }}" />

            <div>
                <label for="service" class="block text-sm font-medium text-gray-700 mb-1">Service</label>
                <select id="service" name="service" class="block w-full rounded-md border-gray-300 shadow-sm sm:text-sm focus:border-cbc-teal focus:ring-cbc-teal">
                    @foreach (\App\Enums\SermonService::cases() as $service)
                        <option value="{{ $service->value }}" @selected($selectedService === $service->value)>{{ $service->label() }}</option>
                    @endforeach
                </select>
            </div>

            <x-input label="Series" id="series" name="series" type="text" value="{{ $sermon->series }}" />

            <x-input label="Reference" id="reference" name="reference" type="text" value="{{ $sermon->reference }}" />

            <x-input label="Preacher" id="preacher" name="preacher" type="text" value="{{ $sermon->preacher }}" />

            <x-textarea
                label="Sermon headings"
                id="points"
                name="points"
                rows="8"
                hint="Sermon outline should be entered as a valid JSON array. E.g., [{&quot;point&quot;:&quot;Main Point 1&quot;, &quot;sub_points&quot;:[&quot;Sub 1.1&quot;]}]"
                class="font-mono"
            >@if(is_array($sermon->points))
{{ json_encode($sermon->points, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
@else
{{ $sermon->points }}
@endif</x-textarea>

            <div>
                <label class="inline-flex items-center">
                    <input type="checkbox" name="show_points" value="1" {{ $sermon->show_points ? 'checked' : '' }} class="rounded border-gray-300 text-cbc-teal shadow-sm focus:border-cbc-teal focus:ring focus:ring-cbc-teal/20">
                    <span class="ml-2 text-sm text-gray-700">Show sermon outline on public page</span>
                </label>
            </div>

            <x-textarea
                label="Sermon summary"
                id="summary"
                name="summary"
                rows="4"
                hint="AI-generated summary of the sermon (optional)"
            >{{ $sermon->summary }}</x-textarea>

            <div>
                <label class="inline-flex items-center">
                    <input type="checkbox" name="show_summary" value="1" {{ $sermon->show_summary ? 'checked' : '' }} class="rounded border-gray-300 text-cbc-teal shadow-sm focus:border-cbc-teal focus:ring focus:ring-cbc-teal/20">
                    <span class="ml-2 text-sm text-gray-700">Show summary on public page</span>
                </label>
            </div>
        </form>
    </x-card>

</x-admin.form-shell>

@stop
