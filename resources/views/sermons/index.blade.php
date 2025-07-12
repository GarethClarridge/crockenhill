@extends('layouts/page')

@section('full_width_content')

@section('dynamic_content')
  @can ('edit-sermons')
    <div class="px-6 max-w-2xl mx-auto mt-6 my-12">
      <x-button link=/christ/sermons/create>
        Upload a new sermon
      </x-button>
    </div>
  @endcan
@stop

@section('full_width_content')

  <x-sermon-list :sermons="$latest_sermons" :groupedByDate="true" />

  <div class="px-6 max-w-2xl mx-auto mt-6">
    <x-button link="/christ/sermons/all">
      Find older sermons
    </x-button>
  </div>
  

@stop
