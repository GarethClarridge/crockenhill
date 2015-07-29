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
                
                    <h1>Page not found</h1>
                            
                    </div>  
                        
                    <ol class="breadcrumb">
                        <li>{{ link_to_route('Home', 'Home') }}</li>
                        <li>404</li>
                    </ol>
         
                    <p>Sorry, that page doesn't exist.</p>
                    <br>

                    <a class="btn btn-primary btn-lg btn-block" href="/">Go to the homepage</a>
                    
                </article>
            </div>
        </div>
  </main>
@stop
