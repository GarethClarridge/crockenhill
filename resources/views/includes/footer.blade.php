<div class="container">
  <div class="row">

    <section class="col-sm-6">
      <div class="row" id="sermons-footer">
        <h4><a href="/sermons">&nbsp&nbsp&nbsp Latest Sermons</a></h4>
        <div class="col-md-6">
          @include('includes.sermon-display', ['sermon' => $morning])
          <br>
        </div>
        <div class="col-md-6">
          @include('includes.sermon-display', ['sermon' => $evening])
        </div>
      </div>
    </section>

    <section class="col-sm-6">
      <div class="row">
        <div class="col-md-6">
          <h4><a href="/contact-us">Get in touch</a></h4>
          <p><span class="glyphicon glyphicon-earphone"></span> &nbsp 01322 663995</p>
          <p><span class="glyphicon glyphicon-envelope"></span> &nbsp <a href="mailto:pastor@crockenhill.org">pastor@crockenhill.org</a>
          </p>
          <p><i class="fa fa-facebook"></i> &nbsp&nbsp&nbsp <a href="https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905">Like us on Facebook</a>
          </p>
          <!--<p class="text-center"><i class="fa fa-twitter"></i> &nbsp&nbsp
            <a href="http://www.twitter.com/crockenhill">Follow us on Twitter</a>
          </p> -->
        </div>

        <div class="col-md-6">
          <h4><a href="/find-us">Address</a></h4>
          <address>
            Crockenhill Baptist Church<br>
            Eynsford Road<br>
            Crockenhill<br>
            Kent<br>
            BR8 8JS<br>
          </address>
        </div>
      </div>
    </section>
  </div>

  <div class="row">
      <section class="col-md-4 col-md-offset-4">
          <a class="fiec-footer" href="http://www.fiec.org.uk">
              <img src="/images/fiec-affiliated-tagline-white.png" width="100%">
          </a>
      </section>

      <section class="col-sm-12">
          <p class="text-center copyright"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
      </section>
  </div>
</div>
