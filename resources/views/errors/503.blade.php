@extends('layouts.main')

@section('title', 'Page not found')

@section('description', '<meta name="description" content="Page not found">')

@section('content')

<span class="nav-no-notch fixed-top-float">&nbsp</span>

  <main id="main-content" class="container mx-auto sm:px-4">
      <div class="flex flex-wrap ">
            <div class="md:w-3/4 pr-4 pl-4">
                <article class="relative flex flex-col min-w-0 rounded break-words border bg-white border-1 border-gray-300 p-0">
                    <div class="header-container">

                    <h1><span>Sorry!<span></h1>

                    </div>

                    <p>Sorry, you don't have permission to access that page.</p>

                    <div class="grid gap-2 m-6">
                      <x-button link="/" variant="primary">Go to the homepage</x-button>
                    </div>
                </article>
            </div>
        </div>
  </main>
@stop
