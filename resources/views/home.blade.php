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

    <div id="mission-statement">
      <div class="white-background mt-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-12 p-3">
              <div class="text-center">
                <p class="h1 mb-3">Crockenhill Baptist Church exists to:</p>
                <p class="h2">Worship God</p>
                <p class="h2">Strengthen believers</p>
                <p class="h2">Proclaim the good news about Jesus Christ to all.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="worship-god">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-12 p-5">
              <div class="text-white text-center">
                <h1>Worshipping God</h1>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-8 col-lg-6">
          <div class="white-background mb-5">
            <p class="text-center">
              We meet to worship God together as a church every Sunday at 10:30am.
            </p>
            <p class="text-center">
              Following
              <a href="https://www.gov.uk/government/publications/covid-19-guidance-for-the-safe-use-of-places-of-worship-during-the-pandemic-from-4-july">current government guidelines</a>
              we're able to meet in person at the church building.
              For those that aren't able to attend in person we're streaming our services on
              <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">our YouTube channel</a>.
            </p>
          </div>
        </div>
      </div>


      <div class="row justify-content-center">
        <div class="col-md-5">
          <div class="card home-options m-2">
            <div class="card-body">
              <p class="card-text">Let us know if you're coming so we can reserve you a socially-distanced seat.</p>
              <a href="mailto:pastor@crockenhill.org,laurie.everest@btinternet.com,pclarridge@gmail.com?subject=Attending church in person" class="btn btn-secondary"><i class="fas fa-user-check"></i>&nbsp Register to attend in person</a>
            </div>
          </div>
        </div>
        <div class="col-md-5">
          <div class="card home-options m-2">
            <div class="card-body">
              <p class="card-text">If you can't join us in person you can follow along live online.</p>
              <a class="btn btn-primary" href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/"><i class="fab fa-youtube"></i>&nbsp Watch on our YouTube channel</a>
            </div>
          </div>
        </div>
      </div>

      <div class="row justify-content-md-center">
        <div class="col-md-8 col-lg-6">
          <div class="white-background my-5">
            <p class="text-center">
              If you've attended a service before coronavirus it will feel quite different.
              <a href="/reopening">Find out what it'll be like before you come</a>.
            </p>
          </div>
        </div>
      </div>

      <div class="row justify-content-md-center">
        <row class="col-md-10">
          <div class="card">
            <div class="card-body">
              <div class="embed-responsive embed-responsive-16by9">
                <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/qtyT7aCOzbc" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div id="strengthen-believers">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-12 p-5">
              <div class="text-white text-center">
                <h1>Strengthening believers</h1>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="container">

        <div class="row justify-content-md-center">
          <div class="col-md-8 col-lg-6">
            <div class="white-background">
              <p class="text-center">
                We want to continue to grow in our faith: becoming closer to God
                as the Holy Spirit makes us more like Jesus.
              </p>
              <p class="text-center">
                How we help each other at the moment is limited.
                Hugs and handshakes are out; WhatsApp groups and Zoom calls are in.
              </p>
              <p class="text-center">
                Thankfully technology means we can still read the Bible together and pray together.
              </p>
            </div>
          </div>
        </div>


        <div class="row justify-content-center">
          <div class="col-md-5">
            <div class="card home-options m-2">
              <div class="card-body">
                <p class="card-text">
                  We have a whole-church prayer meeting on Sundays at 6:30pm over Zoom.
                  This is a good chance for us to all keep in touch when we can't see each other in person,
                  and to pray about things affecting us, our church, our village and our world.
                </p>
                <a href="mailto:pastor@crockenhill.org?subject=Zoom prayer meetings" class="btn btn-secondary"><i class="fas fa-envelope-open-text"></i>&nbsp Ask Mark for an invite</a>
              </div>
            </div>
          </div>
          <div class="col-md-5">
            <div class="card home-options m-2">
              <div class="card-body">
                <p class="card-text">
                  We're continuing our normal small group Bible studies,
                  though they're happening via Zoom at the moment.
                  There are groups on Tuesday, Wednesday and Thursday.
                  We'd love you to join one: do get in touch.
                </p>
                <a class="btn btn-primary" href="/whats-on/bible-study">Find out more</a>
              </div>
            </div>
          </div>
        </div>
    </div>


    <div id="proclaim-christ">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-12 p-5">
              <div class="text-white text-center">
                <h1>Proclaiming the good news of Jesus Christ to all</h1>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="container">

      <div class="row justify-content-md-center">
        <div class="col-md-8 col-lg-6">
          <div class="white-background">
            <p class="text-center">
              We're eager to tell people about Jesus,
              even if we can't run our <a href="/whats-on">usual activities</a>.
              After all, Christianity is good news:
            </p>
          </div>
        </div>
      </div>

      <div class="row justify-content-md-center">
        <div class="col-md-10">
          <div class="card my-5">
            <div class="card-body">
              <div class="embed-responsive embed-responsive-16by9">
                <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/Ue3rHGDMzjU" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row justify-content-md-center">
        <div class="col-md-8 col-lg-6">
          <div class="white-background mb-5">
            <p class="text-center">
              If you've got questions, do <a href="/contact">get in touch</a>.
              We'd love to listen.
            </p>
          </div>
        </div>
      </div>


    </div>










<!-- Archived -->
    <!-- <div class="background-image" id="listening">
      <div class="container">
        <div class="row justify-content-md-center">
          <div class="col-md-10 col-lg-8 text-white p-5">
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
          </div>
        </div>
      </div>
    </div>

    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-10 col-lg-8 p-5">
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
        </div>
      </div>
    </div>

    <div class="background-image" id="building">
      <div class="container">
        <div class="row justify-content-md-center">
          <div class="col-md-10 col-lg-8 text-white p-5">
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
          </div>
        </div>
      </div>
    </div> -->
  </main>

@stop
