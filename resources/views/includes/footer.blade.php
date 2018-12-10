<div class="container">
  <div class="row">
    <div class="col-md-3 mt-2">
      @include('includes.sermon-display', ['sermon' => $morning])
    </div>
    <div class="col-md-3 mt-2">
      @include('includes.sermon-display', ['sermon' => $evening])
    </div>

    <div class="col-md-3 mt-2">
      <div class="card bg-secondary text-white">
        <div class="card-body">
          <p>
            <i class="fa fa-phone"></i>
            01322 663995
          </p>
          <p>
            <i class="fa fa-envelope"></i>
            <a class="card-link text-white" href="mailto:pastor@crockenhill.org">pastor@crockenhill.org</a>
          </p>
          <p>
            <i class="fab fa-facebook-square"></i>
            <a class="card-link text-white" href="https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905">Like us on Facebook</a>
          </p>
        </div>
      </div>
    </div>

    <div class="col-md-3 mt-2">
      <div class="card bg-secondary text-white">
        <div class="card-body">
          <address>
            Crockenhill Baptist Church<br>
            Eynsford Road<br>
            Crockenhill<br>
            Kent<br>
            BR8 8JS<br>
          </address>
        </div>
      </div>
    </div>
  </div>

  <div class="row justify-content-center">
      <section class="col-md-4 mt-4">
          <a class="fiec-footer" href="http://www.fiec.org.uk">
              <img src="/images/fiec-affiliated-tagline-white.png" width="100%">
          </a>
      </section>

      <section class="col-sm-12 mt-4">
          <p class="text-center text-white"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
      </section>
  </div>
</div>
