@extends('layouts.main')

@section('title', 'Page not found')

@section('description', '<meta name="description" content="Page not found">')

@section('content')

<span class="nav-no-notch fixed-top-float">&nbsp</span>

  <main class="container">
      <div class="row">
            <div class="col-md-9">
                <article class="card">
                    <div class="header-container">
                
                    <h1><span>Sorry!<span></h1>
                            
                    </div>  
         
                    <p>Sorry, you don't have permission to access that page.</p>
                    <br>

                    <a class="btn btn-primary btn-lg btn-block" href="/">Go to the homepage</a>
                    
                </article>
            </div>
        </div>
  </main>
@stop