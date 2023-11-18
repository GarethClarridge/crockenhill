@extends('layouts.main')

@section('title')
  {{$heading}}
@stop

@section('description')
  {{ $description }}
@stop

@section('content')
  <main class="mb-3">

    <article>

      @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
        <x-h1-picture>
          {{$heading}}
        </x-h1-picture>
      @else
        <x-h1>
          {{$heading}}
        </x-h1>
      @endif

      <div class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">

        @if (session('message'))
          <x-session-message>
            {{ session('message') }}
          </x-session-message>
        @endif

        <div class="inline-flex items-center justify-between w-full flex-wrap">
          <x-breadcrumbs :$area :$heading/> 

          <x-edit-buttons :$slug />
        </div>
          
        @if (isset ($content))
          <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
          </div>
        @endif

        @yield('dynamic_content')

      </div>


    </article>

    @if (isset ($links))
      <x-h2>
        Related pages
      </x-h2>
      <div class="mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6">
        <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">
          @foreach ($links as $link)
            <x-page-card :page="$link" />
          @endforeach
        </div>
      </div>
    @endif

  </main>
@stop
