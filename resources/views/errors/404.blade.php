@extends('layouts.main')

@section('title', 'Page not found')

@section('description', '<meta name="description" content="Page not found">')

@section('content')

<span class="nav-no-notch fixed-top-float">&nbsp</span>

  <main class="container mx-auto sm:px-4">
      <div class="flex flex-wrap ">
            <div class="md:w-3/4 pr-4 pl-4">
                <article class="relative flex flex-col min-w-0 rounded break-words border bg-white border-1 border-gray-300 p-0">
                    <div class="header-container">

                    <h1><span>Sorry!<span></h1>

                    </div>

                    <p>Sorry, that page doesn't seem to exist.</p>

                    <div class="d-grid gap-2 m-6">
                      <a class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-blue-600 hover:bg-blue-600 py-3 px-4 leading-tight text-xl" href="/">Go to the homepage</a>
                    </div>

                </article>
            </div>
        </div>
  </main>
@stop
