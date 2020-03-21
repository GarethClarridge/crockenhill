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

    <!-- <div class="background-image" id="christmas">
      <div class="container">
        <div class="row justify-content-md-center">
          <section class="col-md-10 col-lg-6 text-white p-5">
              <p>
                Come and celebrate the birth of our Saviour with us this Christmas.
              </p>
              <br>
              <p class="text-center">
                <a href="/christmas" class="text-white px-2 py-1">Christmas events</a>
              </p>
          </section>
        </div>
      </div>
    </div> -->

    <section class="white-background pt-5">
      <div class="container">
        <p class="text-center">
          Sadly we can't meet together as a church in person at the moment, but please join us on Sundays online instead.
        </p>
        <h2 class="text-center">
          <!-- @if ((strtotime('this sunday') === strtotime('second sunday')) && date('m') != '12')
            10:30am & 5:00pm
          @else -->
          <a class="btn btn-lg btn-outline-primary" href="/online" role="button">Online services</a>
        </h2>
        <!-- <p class="text-center">
          <a href="/whats-on/sunday-services">Find out more</a>
        </p> -->
      </div>
    </section>

    <div class="background-image" id="listening">
      <div class="container">
        <div class="row justify-content-md-center">
          <section class="col-md-10 col-lg-6 text-white p-5">
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
        <section class="col-md-10 col-lg-6 p-5">
          <div class="white-background">
            <p class="text-center">
              There are many activities at Crockenhill Baptist Church.
              We have groups during term time for children and young people,
              bible study and prayer groups and more.
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
          <section class="col-md-10 col-lg-6 text-white p-5">
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
