<div class="row">

  <section class="col-md-6 col-lg-3">
    <h4 class="text-center">Latest Morning Sermon</h4>
    <p class="text-center">
      <a href="sermons/{{$morning->slug}}">{{$morning->title}}</a>
    </p>
    <p class="text-center">{{ $morning->reference }}</p>
  </section>

  <section class="col-md-6 col-lg-3">
    <h4 class="text-center">Latest Evening Sermon</h4>
    <p class="text-center">
      <a href="sermons/{{$evening->slug}}">{{$evening->title}}</a>
    </p>
    <p class="text-center">{{ $evening->reference }}</p>
  </section>

  <section class="col-md-6 col-lg-3">
    <h4 class="text-center">Get in touch</h4>
    <p class="text-center"><span class="glyphicon glyphicon-earphone"></span> &nbsp&nbsp 01322 663995</p>
    <p class="text-center"><span class="glyphicon glyphicon-envelope"></span> &nbsp&nbsp 
      <a href="mailto:pastor@crockenhill.org">pastor@crockenhill.org</a>
    </p>
    <p class="text-center"><i class="fa fa-facebook"></i> &nbsp&nbsp 
        <a href="https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905">Like us on Facebook</a>
    </p>
    <!--<p class="text-center"><i class="fa fa-twitter"></i> &nbsp&nbsp 
      <a href="http://www.twitter.com/crockenhill">Follow us on Twitter</a>
    </p> -->
  </section>

  <section class="col-md-6 col-lg-3">
    <h4 class="text-center">Address</h4>
    <address class="text-center">
      Crockenhill Baptist Church<br />
      Eynsford Road<br />
      Crockenhill<br />
      Kent<br />
      BR8 8JS<br />
    </address>
  </section>
</div>

<div class="row">
    <section class="col-md-6 col-md-offset-3">
        <a href="http://www.fiec.org.uk">
            <img src="/images/fiec-affiliated-tagline-white.png" width="100%">
        </a>
    </section>
    
    <section class="col-sm-12">
        <p class="text-center copyright"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
    </section>
</div>

