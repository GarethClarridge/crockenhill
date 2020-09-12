<div class="container">
  <div class="row">
    <!-- Removing sermons as out of date during Covid19
    <div class="col-md-3 mt-2">
      @include('includes.sermon-display', ['sermon' => $morning])
    </div>
    <div class="col-md-3 mt-2">
      @include('includes.sermon-display', ['sermon' => $evening])
    </div> -->
    <div class="col-md-6 mt-2">
      <div class="card text-center">
        <div class="card-body">
          <h3 class="card-title">
            Last week's service
          </h3>
          <div class="mb-3 embed-responsive embed-responsive-16by9">
            <iframe id="last_week_youtube_video" class="embed-responsive-item" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>

          </div>
        </div>
        <div class="card-footer text-muted">
          <a class="card-link" href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA">
            See more services on our YouTube channel.
          </a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3 mt-2">
      <div class="card text-center">
        <div class="card-body">
          <h3 class="card-title">
            Contact us
          </h3>
          <ul class="list-group">
            <li class="list-group-item">
              <i class="fa fa-phone"></i> &nbsp
              01322 663995
            </li>
            <li class="list-group-item">
              <i class="fa fa-envelope"></i> &nbsp
              <a class="card-link" href="mailto:pastor@crockenhill.org">pastor@crockenhill.org</a>
            </li>
            <li class="list-group-item">
              <i class="fab fa-facebook-square"></i> &nbsp
              <a class="card-link" href="https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905">Like us on Facebook</a>
            </li>
            <li class="list-group-item">
              <i class="fab fa-youtube"></i> &nbsp
              <a class="card-link" href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA">Subscribe to our YouTube channel</a>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3 mt-2">
      <div class="card text-center">
        <div class="card-body">
          <h3 class="card-title">
            Address
          </h3>
          <address>
            Crockenhill Baptist Church<br>
            Eynsford Road<br>
            Crockenhill<br>
            Kent<br>
            BR8 8JS
          </address>
        </div>
      </div>
    </div>
  </div>

  <div class="row justify-content-center">
    <section class="col-md-4 mt-4">
      <img src="/svg/OutlineNameWhite.svg" width="100%" alt="Crockenhill Baptist Church logo">
    </section>

    <section class="col-md-4 mt-4">
        <a class="fiec-footer" href="http://www.fiec.org.uk">
          <img src="/images/fiec-affiliated-tagline-white.png" width="100%" alt="Affiliated to the Fellowship of Independent Evangelical Churches">
        </a>
    </section>

    <section class="col-sm-12 mt-4">
        <p class="text-center text-white"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
    </section>
  </div>
</div>
