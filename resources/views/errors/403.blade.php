@extends('layouts.main')

@section('title', 'Page not found')

@section('description', '<meta name="description" content="Page not found">')

@section('content')

<span class="nav-no-notch fixed-top-float">&nbsp</span>

  <main class="container">
      <div class="row">
            <div class="col-md-9">
                <article class="card p-0">
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
