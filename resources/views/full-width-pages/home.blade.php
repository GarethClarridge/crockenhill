@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('description', '<meta name="description" content="We are an independent evangelical church located in the village of Crockenhill in Kent.">')

@section('content')

  <main id="home">
    <div class="full-width-head text-white">
      <div class="container">
        <h1 class="text-center">
          <div class="px-2 py-1">Crockenhill Baptist Church</div>
        </h1>
        <p class="text-center lead"><span class="px-3 py-2">A friendly, Bible believing church just outside Swanley.</span></p>
      </div>
    </div>

    <div id="remembrance">
      <div class="bg-primary mb-5">
        <div class="container">
          <div class="row justify-content-xs-center p-5">
            <div class="col-md-2">
              <img src="/images/homepage/PoppyAppeal.png" class="" alt="Poppy">
            </div>
            <div class="col-md-9 offset-md-1 text-white">
              <h1 class="mt-0">Remembrance Sunday</h1>
              <p>
                We can't meet in person for Remembrance Sunday this year, but
                we'll post our service on
                <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">
                  our YouTube channel</a> later today (we've had problems with
                  our internet connection today).
              </p>
              <p>
                Mark is also featured in the service produced by
                <a href="https://www.crockenhillpc.org.uk/">
                  the Parish Council</a>, which is available on
                <a href="https://www.facebook.com/crockenhillpc">
                  the council Facebook page</a>. This is instead of the usual
                  service at the war memorial.
              </p>
              <p>
                We're also invited to a joint service this evening with other
                evangelical churches in the area. Let us know if you want the
                Zoom link.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="mission-statement">
      <div class="white-background mt-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-12 p-3">
              <div class="text-center">
                <p class="display-1 mb-5">We exist to:  </p>
                <p class="h2 my-4 mission-statement-link">
                  <a href="#worship-god" class="py-2">
                    <!-- <i class="fas fa-cross"></i> -->
                    Worship God
                    <!-- <i class="fas fa-cross"></i> -->
                  </a>
                </p>
                <p class="h2 my-4 mission-statement-link">
                  <a href="#strengthen-believers" class="py-2">
                    <!-- <i class="fas fa-users"></i> -->
                    Strengthen believers
                    <!-- <i class="fas fa-users"></i> -->
                  </a>
                </p>
                <p class="h2 mission-statement-link">
                  <a href="#proclaim-christ" class="py-2">
                    <!-- <i class="fas fa-bullhorn fa-bullhorn-left"></i> -->
                    Proclaim the good news of Jesus Christ to all
                    <!-- <i class="fas fa-bullhorn"></i> -->
                  </a>
                </p>
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
              We usually meet to worship God together as a church every Sunday at 10:30am.
            </p>
            <p class="text-center">
              Unfortunately due to
              <a href="https://www.gov.uk/government/new-national-restrictions-from-5-november#weddings-civil-partnerships-religious-services-and-funerals">
                the government's new national restrictions
              </a>
              we can't meet in person for the rest of November.
            </p>
            <p class="text-center">
              We will still be streaming our services on
              <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">
                our YouTube channel
              </a> from at 10:30am on Sundays.
            </p>
          </div>
        </div>
      </div>


      <!-- <div class="row justify-content-center">
        <div class="col-md-5">
          <div class="card home-options text-center m-2">
            <div class="card-body">
              <p class="card-text">Let us know if you're coming so we can reserve you a socially-distanced seat.</p>
              <a href="mailto:pastor@crockenhill.org,laurie.everest@btinternet.com,pclarridge@gmail.com?subject=Attending church in person" class="btn btn-secondary"><i class="fas fa-user-check"></i>&nbsp Register to attend in person</a>
            </div>
          </div>
        </div>
        <div class="col-md-5">
          <div class="card home-options text-center m-2">
            <div class="card-body">
              <p class="card-text">If you can't join us in person you can follow along live online.</p>
              <a class="btn btn-primary" href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/"><i class="fab fa-youtube"></i>&nbsp Watch on our YouTube channel</a>
            </div>
          </div>
        </div>
      </div> -->

      <!-- <div class="row justify-content-md-center">
        <div class="col-md-8 col-lg-6">
          <div class="white-background my-5">
            <p class="text-center">
              If you've attended a service before coronavirus it will feel quite different.
              <a href="/reopening">Find out what it'll be like before you come</a>.
            </p>
          </div>
        </div>
      </div> -->

      <div class="row justify-content-md-center">
        <row class="col-md-10">
          <div class="card">
            <div class="card-body">
              <div class="embed-responsive embed-responsive-16by9">
                <iframe class="embed-responsive-item" id="latest_youtube_video" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
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
                Thankfully technology means that even in lockdown we can still
                read the Bible together and pray together.
              </p>
            </div>
          </div>
        </div>


        <div class="row justify-content-center">
          <div class="col-md-10">
            <div class="card-deck">
              <div class="card home-options text-center m-2">
                <div class="card-body">
                  <p class="card-text">
                    We have a whole-church prayer meeting on Sundays at 6:30pm.
                    The meeting is held over Zoom, but if you don't have access
                    to Zoom you can dial in using a landline phone.
                  </p>
                  <p class="card-text">
                    This is a good chance for us to all keep in touch when we can't see each other in person,
                    and to pray about things affecting us, our church, our village and our world.
                  </p>
                </div>
                <div class="card-footer">
                  <a href="mailto:pastor@crockenhill.org?subject=Zoom prayer meetings" class="btn btn-secondary">
                    <i class="fas fa-envelope-open-text"></i> &nbsp
                    Ask Mark for an invite
                  </a>
                </div>
              </div>
              <div class="card home-options text-center m-2">
                <div class="card-body">
                  <p class="card-text">
                    We're continuing our normal small group Bible studies,
                    though they're happening via Zoom at the moment.
                  </p>
                  <p class="card-text">
                    There are groups on Tuesday, Wednesday and Thursday.
                    We'd love you to join one: do get in touch.
                  </p>
                </div>
                <div class="card-footer">
                  <a class="btn btn-primary" href="/community/bible-study">
                    Find out more &nbsp
                    <i class="fas fa-arrow-circle-right"></i>
                  </a>
                </div>
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
              even if we can't run our <a href="/community">usual activities</a>.
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
                <iframe src="https://www.youtube.com/embed/Ue3rHGDMzjU" class="embed-responsive-item" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row justify-content-md-center">
        <div class="col-md-8 col-lg-6">
          <div class="white-background text-center mb-5">
            <a class="btn btn-lg btn-primary my-5" href="/christ">
              Find out more about the good news of Jesus Christ &nbsp
              <i class="fas fa-arrow-circle-right"></i>
            </a>
            <p class="text-center">
              If you've got questions, do get in touch using the details below.
              We'd love to listen.
            </p>
          </div>
        </div>
      </div>


    </div>
  </main>

@stop
