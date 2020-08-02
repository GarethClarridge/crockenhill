@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('description', '<meta name="description" content="We are an independent evangelical church located in the village of Crockenhill in Kent.">')

@section('content')

  <main id="home">
    <div class="home-head text-white">
      <div class="container">
        <h1 class="text-center">
          <div class="px-2 py-1">Crockenhill Baptist Church</div>
        </h1>
        <p class="text-center lead"><span class="px-3 py-2">A friendly, Bible believing church just outside Swanley.</span></p>
      </div>
    </div>

    <div class="pt-5" id="online">
      <div class="container">
        <div class="row justify-content-md-center">
          <section class="col-md-10 col-lg-8 p-5">
            <div class="white-background">
              <p class="text-center">
                Following updated government advice, we will be meeting at the
                church building to worship God together at 10:30am on Sundays.
              </p>
              <p class="text-center">
                Not everyone will be able to join us, so we will also be live-streaming
                the service here and <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">on our YouTube channel</a>.
              </p>
              <p class="text-center">
                The service will feel quite different, so please click the link and
                familiarise yourself with the information below.
              </p>
              <p class="mt-5">
                <small>
                  <a href="/reopening" class="btn btn-secondary" id="online-service-btn">What you need to know about reopening</a>
                </small>
              </p>
              <br>
              <hr>
              <br>
              <p class="text-center">
                You'll find the live streamed service here from 10:30am on Sunday morning.
                If you miss it you'll be able to catch up later.
                Previous services are available <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">on our YouTube channel</a>.
              </p>
              <div class="mb-3 embed-responsive embed-responsive-16by9">
                <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/HiLlfYRvPL4" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
              </div>
              <div class="text-dark mb-3 text-center">
                  <a class="btn btn-primary btn-lg" href="https://www.youtube.com/watch?v=HiLlfYRvPL4" role="button"><i class="fab fa-youtube"></i>&nbsp Watch on YouTube</a>
              </div>
              <br>
              <hr>
              <br>
              <p class="text-center">
                <a href="/online">Catch up on previous online services</a>
                and view our
                <a href="/resources">resources for isolation</a>.
              </p>
            </div>
          </section>
        </div>
      </div>
    </div>

    <div class="background-image" id="listening">
      <div class="container">
        <div class="row justify-content-md-center">
          <section class="col-md-10 col-lg-8 text-white p-5">
              <p class="text-center">
                Our church exists to worship God,
                strengthen believers in their faith,
                and to proclaim the good news of Christianity to all,
                so that others might experience the wonderful
                salvation of God through faith in Jesus Christ.
              </p>
              <br>
              <p class="text-center">
                <a href="/about-us" class="text-white px-2 py-1">Find out more about us</a>
              </p>
          </section>
        </div>
      </div>
    </div>

    <div class="container">
      <div class="row justify-content-md-center">
        <section class="col-md-10 col-lg-8 p-5">
          <div class="white-background">
            <p class="text-center">
              During more normal times we run lots of activities to let as many
              people as possible know the good news about Jesus.
              We have Bible study and prayer groups meeting in people's homes,
              and during term time groups for everyone from babies, children
              and young people through to the more mature among us!
            </p>
            <br>
            <p class="text-center">
              <a href="/whats-on">Find out what's on</a>
            </p>
          </div>
        </section>
      </div>
    </div>

    <div class="background-image" id="building">
      <div class="container">
        <div class="row justify-content-md-center">
          <section class="col-md-10 col-lg-8 text-white p-5">
              <p class="text-center">
                We are located in the village of Crockenhill in Kent,
                which is a mile or so south of Swanley
                and two miles from St Mary Cray.
                If you are travelling from a distance,
                we are just off junction 3 of the M25.
              </p>
              <br>
              <p class="text-center">
                <a href="/find-us" class="text-white px-2 py-1">Get directions</a>
              </p>
          </section>
        </div>
      </div>
    </div>
  </main>

@stop
