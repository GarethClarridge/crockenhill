@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('description', '<meta name="description" content="We are an independent evangelical church located in the village of Crockenhill in Kent.">')

@section('content')

  <main id="home">
    <div class="home-head">
      <div class="container">
        <h1 class="text-center"><span>Crockenhill Baptist Church</span></h1>
        <p class="text-center lead"><span>A friendly, Bible believing church just outside Swanley.</span></p>
        <!-- <p class="text-center arrow">
          <a href="#video">
            <span class="glyphicon glyphicon-chevron-down"></span>
          </a>
        </p> -->
      </div>
    </div>

    <section class="white-background">
      <p>
        Join us this Sunday
      </p>
      <h2>

      @if ((strtotime('this sunday') === strtotime('second sunday')) && date('m') != '12')
        10:30am & 5:00pm
      @else
        10:30am & 6:30pm
      @endif

      </h2>
      <p>
        <a href="/whats-on/sunday-services">Find out more</a>
      </p>
    </section>

    <div class="background-image" id="christmas">
      <div class="container">
        <div class="row">
          <section class="col-md-6 col-md-offset-3">
              <p>
                Come and celebrate the birth of our Saviour with us this Christmas.
              </p>
              <br>
              <p class="text-center">
                <a href="/christmas" class="btn btn-default btn-outline btn-lg">Christmas events</a>
              </p>
          </section>
        </div>
      </div>
    </div>

    <div class="background-image" id="listening">
      <div class="container">
        <div class="row">
          <section class="col-md-6 col-md-offset-3">
              <p>
                Our church exists to worship God,
                strengthen believers in their faith,
                and to proclaim the good news of Christianity to all,
                so that others might experience the wonderful
                salvation of God through faith in Jesus Christ.
              </p>
              <br>
              <p class="text-center">
                <a href="/about-us" class="btn btn-primary btn-lg">Find out more about us</a>
              </p>
          </section>
        </div>
      </div>
    </div>

    <div class="container">
      <div class="row">
        <section class="col-md-6 col-md-offset-3">
          <div class="white-background">
            <p>
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
        <div class="row">
          <section class="col-md-6 col-md-offset-3">
              <p>
                We are located in the village of Crockenhill in Kent,
                which is a mile or so south of Swanley
                and two miles from St Mary Cray.
                If you are travelling from a distance,
                we are just off junction 3 of the M25.
              </p>
              <br>
              <p class="text-center">
                <a href="/find-us" class="btn btn-primary btn-lg">Get directions</a>
              </p>
          </section>
        </div>
      </div>
    </div>

  </main>

@stop
