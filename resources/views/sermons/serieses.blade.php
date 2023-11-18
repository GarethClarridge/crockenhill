@extends('layouts/page')

@section('dynamic_content')

<ul class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">
  @foreach ($series as $series)
    @isset ($series->series)
      <li class="text-center p-3">
        <x-clickable-card 
          heading="{{$series->series}}" 
          link="series/{!! \Illuminate\Support\Str::slug($series->series) !!}"
          content="" />
      </li>
    @endisset
  @endforeach
</ul>

@stop
