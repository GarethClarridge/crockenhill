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

                    <h1><span>Unauthorised<span></h1>

                    </div>

                    <p>Sorry, your account doesn't have the right permissions to access that page.</p>
                    <p>To get access please talk to Gareth.</p>
                    <br>

                </article>
            </div>
        </div>
  </main>
@stop
