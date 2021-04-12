@extends('layouts.main')

@section('title')
  Community
@stop

@section('description')
  Community
@stop

@section('content')
  <main class="full-width-page">
    <div>
      <div class="white-background">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-12 p-5">
              <div class="text-white text-center">
                <h1 class="py-5 text-body full-width-heading">
                  Community
                </h1>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="white-background">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-10 col-lg-8">
              <div class="text-center">
                <p>
                  We're a local church - in Crockenhill, for the people of
                  Crockenhill. We want to use the gifts God has given us to
                  serve our community.
                </p>
                <p>
                  We believe the best way we can help people is by making
                  <a href="/christ">the good news about Jesus Christ</a> known
                  to everyone in Crockenhill and the surrounding area, although
                  we're also keen to help meet people's physical needs where we
                  can.
                </p>
                <p>
                  Our activities are open and welcoming to everyone: whether
                  you're a committed Christian or just someone who wants a cup
                  of coffee and a chat with local people.
                </p>
                <div class="my-5 in-page-nav-buttons">
                  <a class="btn btn-lg btn-primary" href="#pantry" role="button">I need help with food or basic necessities</a>
                  <a class="btn btn-lg btn-primary" href="#meet-people" role="button">I want to meet local people</a>
                  <a class="btn btn-lg btn-primary" href="#children" role="button">I've got children</a>
                  <a class="btn btn-lg btn-primary" href="#jesus" role="button">I want to find out more about Jesus</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>




    <div id="pantry">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="p-5">
              <div class="text-white text-center">
                <h2 class="h1">
                  I need help with food or basic necessities
                </h2>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-12">
          <div class="white-background mb-5">

            <div class="row justify-content-center my-5">
              <div class="col-md-10 col-lg-8">
                <div class="text-center">
                  <p>
                    We know life is difficult at the moment, so we're pleased
                    to offer our building to host the Crockenhill Community
                    Pantry on Wednesday evenings and Friday afternoons.
                  </p>
                  <p>
                    The Crockenhill Community Pantry is run by local district
                    Councillor Rachel Waterton (not by the church), with support
                    from her team of volunteers and local groups.
                  </p>
                  <a class="btn btn-lg btn-primary mt-3" href="http://crockenhillvillagehall.co.uk/posts/2020/crockenhills-community-pantry/" role="button">
                    Find out more at the Parish Council website &nbsp
                    <i class="fas fa-arrow-circle-right"></i>
                  </a>
                </div>
              </div>

            </div>

          </div>
        </div>
      </div>
    </div>



    <div id="meet-people">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="p-5">
              <div class="text-white text-center">
                <h2 class="h1">
                  I want to meet local people
                </h2>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-12">
          <div class="white-background mb-5">

            <div class="row justify-content-center my-5">
              <div class="card-deck">

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Coffee Cup
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/coffee-cup.jpg">
                  </div>
                  <div class="card-body">
                    If you're free on Thursday mornings, come along to the church
                    for a cup of coffee and a slice of cake.
                    <div class="alert alert-danger mt-3">
                      Coffee Cup isn't meeting at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/coffee-cup">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Baby Talk
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/baby-talk.jpg">
                  </div>
                  <div class="card-body">
                    If you've got a pre-school age child (or can borrow one!)
                    join us on Wednesdays for Baby Talk.
                    <div class="alert alert-danger mt-3">
                      Baby Talk isn't meeting at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/baby-talk">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Sunday mornings
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/sunday-services.jpg">
                  </div>
                  <div class="card-body">
                    Of course, you could always come along to our Sunday service.
                    We'd love to get to know you.
                    If this sounds scary you can
                    <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA">
                      check them out on Youtube
                    </a> first!
                  </div>
                  <div class="card-footer">
                    <a href="community/sunday-services">Find out more ...</a>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>




    <div id="children">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="p-5">
              <div class="text-white text-center">
                <h2 class="h1">
                  I've got children
                </h2>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-12">
          <div class="white-background mb-5">

            <div class="row justify-content-center my-5">
              <div class="card-deck">

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Baby Talk
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/baby-talk.jpg">
                  </div>
                  <div class="card-body">
                    If you've got a pre-school age child (or can borrow one!)
                    join us on Wednesdays for Baby Talk.
                    <div class="alert alert-danger mt-3">
                      Baby Talk isn't meeting at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/baby-talk">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Adventurers
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/buzz-club.jpg">
                  </div>
                  <div class="card-body">
                    A club on Wednesday evenings for children aged 6 to 9.
                    <div class="alert alert-danger mt-3">
                      Adventurers isn't meeting at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/adventurers">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        11:50
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/1150.jpg">
                  </div>
                  <div class="card-body">
                    A club on Friday evenings for children aged 10 to 12.
                    <div class="alert alert-danger mt-3">
                      11:50 isn't meeting at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/1150">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Messy Church
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/messy-church.jpg">
                  </div>
                  <div class="card-body">
                    Church for all the family - every other month on Sunday afternoons.
                    <div class="alert alert-danger mt-3">
                      Messy Church isn't meeting at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/messy-church">Find out more ...</a>
                  </div>
                </div>

                <!-- <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Buzz Club
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/buzz-club.jpg">
                  </div>
                  <div class="card-body">
                    A holiday club for primary school age children.
                  </div>
                  <div class="card-footer">
                    <a href="buzz-club">Find out more ...</a>
                  </div>
                </div> -->

              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div id="jesus">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="p-5">
              <div class="text-white text-center">
                <h2 class="h1">
                  I want to meet local people
                </h2>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-12">
          <div class="white-background mb-5">

            <div class="row justify-content-center my-5">
              <div class="card-deck">

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Sunday services
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/sunday-services.jpg">
                  </div>
                  <div class="card-body">
                    The best way to find out more about Jesus and what it means
                    to follow him is to come along to one of our Sunday services.
                    If this sounds scary you can
                    <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA">
                      check them out on Youtube
                    </a> first!
                  </div>
                  <div class="card-footer">
                    <a href="community/sunday-services">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Christianity Explored
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/christianity-explored.jpg">
                  </div>
                  <div class="card-body">
                    Every now and then we run a short, informal course for people
                    interested in learning more about Christianity.
                  </div>
                  <div class="card-footer">
                    <a href="/christianity-explored">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Bible studies
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/bible-study.jpg">
                  </div>
                  <div class="card-body">
                    We run several groups that meet in each other's houses each
                    week to study the Bible and pray together. These are aimed
                    primarily at Christians, but anyone is welcome!
                    <div class="alert alert-info mt-3">
                      Our small groups are meeting via Zoom at the moment due to
                      coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="community/bible-study">Find out more ...</a>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div id="occasional">
      <div class="bg-pattern my-5">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="p-5">
              <div class="text-white text-center">
                <h2 class="h1">
                  Occasional events
                </h2>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-12">
          <div class="white-background mb-5">

            <div class="row justify-content-center my-5">
              <div class="card-deck">

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Quiz nights
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
                  </div>
                  <div class="card-body">
                    A couple of times a year we run quiz nights for the local community.
                    <div class="alert alert-danger mt-3">
                      We won't be hosting quiz nights at the moment due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="quiz-night">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Carols at the Chequers
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/carols-in-the-chequers.jpg">
                  </div>
                  <div class="card-body">
                    Every Christmas we have an evening of carol singing in the village pub.
                  </div>
                  <div class="card-footer">
                    <a href="carols-in-the-chequers">Find out more ...</a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2">
                        Buzz Club
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/buzz-club.jpg">
                  </div>
                  <div class="card-body">
                    A holiday club for primary school age children.
                    <div class="alert alert-danger mt-3">
                      We won't run Buzz Club in 2021 due to coronavirus.
                    </div>
                  </div>
                  <div class="card-footer">
                    <a href="buzz-club">Find out more ...</a>
                  </div>
                </div>

              </div>
            </div>

          </div>
        </div>
      </div>

    </div>
  </main>

@stop
