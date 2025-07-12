@extends('layouts/page')

@section('dynamic_content')

  @can ('edit-sermons')
    <div class="px-6 max-w-2xl mx-auto mt-6">
      <x-button link=/christ/sermons/create>
        Upload a new sermon
      </x-button>
    </div>
  @endcan

  @foreach ($latest_sermons as $date => $sermons)
    <section id="week-{{$date}}" class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-4xl mx-auto mb-6">
      <h3 class="w-full font-display text-center text-xl mt-12 mb-6">
        {{ date_format(date_create($sermons[0]->date),'l jS F Y') }}
      </h3>
      @foreach ($sermons as $sermon)
        <x-sermon-card :$sermon/>
      @endforeach
    </section>
  @endforeach

  <div class="px-6 max-w-2xl mx-auto mt-6">
    <x-button link="/christ/sermons/all">
      Find older sermons
    </x-button>
  </div>
  

@stop
