@extends('layouts.main')

@section('title')
  Resources
@stop

@section('description')
  Resources for isolation
@stop

@section('content')
  <main class="container mb-3">
    <div class="row justify-content-md-center">
      <div class="col-md-10 col-lg-9">
        <article class="card mt-3">
          <div class="card-img-caption d-flex align-items-center">
            <h1 class="card-text text-white">
              <div class="px-2 py-1">
                Good Friday
              </div>
            </h1>
            <img class="card-img-top cbc-card-img-top" src="/images/homepage/easter.jpg">
          </div>

          <div class="card-body">
            <div class="main-content">
              <p>We're joining with our friends at 4 other churches for a joint Good Friday service.</p>
              <div class="text-dark mb-3 text-center">
                <a class="btn btn-primary btn-lg" href="https://www.youtube.com/watch?v=HbUDCTtC08o" role="button"><i class="fab fa-youtube"></i>&nbsp Watch on YouTube</a>
              </div>
              <div class="embed-responsive embed-responsive-16by9">
                <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/HbUDCTtC08o" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
              </div>
            </div>
          </div>
        </article>
      </div>
    </div>

    <div class="row justify-content-md-center">
      <div class="col-md-10 col-lg-9">
        <article class="card mt-3">
          <div class="card-img-caption d-flex align-items-center">
            <h1 class="card-text text-white">
              <div class="px-2 py-1">
                Easter Sunday
              </div>
            </h1>
            <img class="card-img-top cbc-card-img-top" src="/images/homepage/easter.jpg">
          </div>

          <div class="card-body">
            <div class="main-content">
              <p>Our Easter Sunday service will be here soon.</p>
              <div class="text-dark mb-3 text-center">
            </div>
          </div>
        </article>
      </div>
    </div>
  </main>
@stop
