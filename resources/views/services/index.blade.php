@extends('layouts/page')

@section('dynamic_content')

@can ('edit-sermons')
<div class="my-12">
  <x-button link=/church/members/services/create>
    Upload a new service
  </x-button>
</div>
@endcan

@foreach ($services as $service)
<h3 class="w-full font-display text-center text-xl mt-12 mb-6">
  {{ date_format(date_create($service->date),'l jS F Y') }}
</h3>
{{$service->video}}
</section>
@endforeach


@stop