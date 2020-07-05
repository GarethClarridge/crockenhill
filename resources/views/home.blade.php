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
                We hope to meet together as a church family soon.
                Sadly, having examined the
                <a href="https://www.gov.uk/government/publications/covid-19-guidance-for-the-safe-use-of-places-of-worship-during-the-pandemic-from-4-july">government guidance</a>
                and
                <a href="https://fiec.org.uk/resources/series/coronavirus">advice from the FIEC</a>,
                we have concluded that we are not yet in a position to meet.
              </p>
              <p class="text-center">
                For now, please continue to join us online on Sundays.
              </p>
              <p class="text-center">
                <a href="/online" class="btn btn-primary btn-lg" id="online-service-btn">Online services</a>
              </p>
              <p class="text-center mb-3">
                We have an online service every week, with prayers, Bible readings,
                songs and Mark preaching the good news about Jesus to us from the Bible.
              </p>
              <p class="text-center mb-3">
                It is disappointing that we can't yet meet in person,
                but we are sure that you will understand our duty of care to all members and visitors.
                We will of course continue to work towards meeting as soon as it is safe and wise to do so.
              </p>
              <br>
              <hr>
              <br>
              <p>
                We want to keep growing our faith in Christ during this unexpected
                separation. We've prepared a list of things we can be talking
                to God about in prayer, and a list of online resources to help us
                learn more about him.
              </p>
              <p class="mt-3">
                <small>
                  <a href="/resources" class="btn btn-secondary" id="online-service-btn">Resources for isolation</a>
                </small>
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
