@extends('layouts/page')

@section('full_width_content')

@section('dynamic_content')
@can('manage-sermons')
<div class="px-6 max-w-2xl mx-auto mt-6 my-12">
  <x-button link="{{ route('admin.sermon-upload.create') }}">
    Upload a new sermon
  </x-button>
</div>
@endcan
@stop

@section('full_width_content')

<x-sermon-list :sermons="$latest_sermons" :groupedByDate="true" />

<div class="mt-8">
  <x-public-cta
    link="/christ/sermons/all"
    label="Find older sermons"
    ariaLabel="Find older sermons"
  />
</div>


@stop
