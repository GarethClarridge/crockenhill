@extends('layouts/page')

@section('dynamic_content')

<ul class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">
  @foreach ($series as $seriesName)
    <li class="text-center p-3">
      <x-clickable-card
        heading="{{ $seriesName }}"
        link="series/{{ \Illuminate\Support\Str::slug($seriesName) }}"
        content="" />
    </li>
  @endforeach
</ul>

@stop
