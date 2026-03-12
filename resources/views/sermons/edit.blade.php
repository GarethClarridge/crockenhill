@extends('layouts/page')

@section('dynamic_content')

@if (session('message'))
    <div class="relative mb-4 rounded border border-green-300 bg-green-200 px-3 py-3 text-green-800" role="alert">
        {{ session('message') }}
    </div>
@endif

<form method="POST" action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" accept-charset="UTF-8" class="space-y-4">
    @csrf

    <x-input label="Title" id="title" name="title" type="text" value="{{ $sermon->title }}" />

    <x-input label="Date" id="date" name="date" type="date" value="{{ $sermon->date ? $sermon->date->format('Y-m-d') : '' }}" />

    @php
        $serviceOptions = collect(\App\Enums\SermonService::cases())->map(fn ($s) => ['id' => $s->value, 'name' => $s->label()])->all();
        $selectedService = $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->value : $sermon->service;
    @endphp
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

    <div class="flex gap-3 pt-2">
        <x-form-button variant="primary" size="lg">Save</x-form-button>
        <x-button link="/sermons" variant="outline" size="lg" inline>Cancel</x-button>
    </div>

</form>

@endsection
