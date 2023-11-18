@extends('layouts/page')

@section('dynamic_content')

  @foreach ($sermons as $date => $sermons)
    <section id="week-{{$date}}" class="flex flex-wrap justify-center mb-6">
      <h3 class="w-full font-display text-center text-xl mt-12 mb-6">
        {{ date_format(date_create($sermons[0]->date),'l jS F Y') }}
      </h3>
      @foreach ($sermons as $sermon)
        <x-sermon-card :$sermon/>
      @endforeach
    </section>
  @endforeach

@stop
