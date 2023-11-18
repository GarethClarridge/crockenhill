@extends('layouts/page')

@section('dynamic_content')

  <ul class="">
    @foreach ($preachers as $preacher)
      <li class="text-center p-3 text-lg">
        <x-clickable-card 
            heading="" 
            link="preachers/{!! \Illuminate\Support\Str::slug($preacher[1]) !!}">
            <h5 class="mb-2 text-2xl font-bold tracking-tight text-gray-900 inline-block">
                {{ $preacher[1] }}
            </h5>
          <small class="bg-slate-800 text-white rounded-full py-1 px-2 ms-2">
            {!! $preacher[0] !!}
          </small>
        </x-clickable-card>
      </li>
    @endforeach
  </ul>


@stop
