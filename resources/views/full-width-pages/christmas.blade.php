@extends('layouts.main')

@section('title')
  Christmas
@stop

@section('description')
  Christmas at Crockenhill Baptist Church
@stop

@section('content')
<main>
  <div id="christmas">
    <div class="christmas-background">
      <div class="container">
        <div class="row justify-content-md-center">
          <div class="col-md-10 col-lg-6">
            <div class="text-center mt-5">
              <img src="/images/GoodNewsGreatJoy.svg" alt="Good news of great joy!">
            </div>
          </div>
          <div class="col-md-12">
            <div class="text-center text-white m-4">
              <h1>Christmas with Crockenhill Baptist Church:</h1>
            </div>
          </div>
        </div>

        <div class="row justify-content-center my-3">
          <div class="col-md-10">
            <div class="card-deck pb-4">
              <div class="card home-options text-center p-2">
                <div class="card-body">
                  <h2>Carols by Candlelight</h2>
                  <p>
                    Sunday 20th at 6pm, only online via <a href="https://www.youtube.com/watch?v=UVxy545ELC0">
                    our YouTube channel</a>.
                  </p>
                </div>
              </div>
              <div class="card home-options text-center p-2">
                <div class="card-body">
                  <h2>Christmas day service</h2>
                  <p>
                    At 10:30am on Christmas morning, only online via <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">
                    our YouTube channel</a>.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row justify-content-md-center">
          <div class="col-md-12">
            <div class="card">
              <div class="card-body p-5">
                <div class="embed-responsive embed-responsive-16by9">
                  <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/UVxy545ELC0" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row justify-content-md-center">
          <div class="col-md-6">
            <div class="text-center text-white my-5">
              <p class="h4">We'll continue
                <a class="text-white" href="#worship-god">
                  meeting together on Sunday mornings in person
                  and online</a> as usual.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>











  <!-- 2019: <main id="christmas-page">
    <div class="christmas-head text-white">
      <div class="container">
        <h1 class="text-center">
          Christmas at Crockenhill Baptist Church
        </h1>
      </div>
    </div>

    <section class="white-background pt-5">
      <div class="container">
        <h2 class="text-center">
          Adventurers' presentation
        </h2>
        <p class="text-center">
          A Christmas presentation by
          <a href="/community/adventurers">Adventurers</a>,
          our group for 6-9 year olds.
          Everyone is welcome, but families of children in Adventurers are especially invited.
        </p>
        <h3 class="text-center">
          Sunday 13 December, 10:30-11:30
        </h3>
      </div>
    </section>

    <section class="white-background pt-5">
      <div class="container">
        <h2 class="text-center">
          Carols at the Chequers
        </h2>
        <p class="text-center">
          An evening of Christmas carol singing in the village pub,
          at the kind invitation of the landlord.
        </p>
        <h3 class="text-center">
          Wednesday 16 December, from 7:30pm
        </h3>
      </div>
    </section>

    <section class="white-background pt-5">
      <div class="container">
        <h2 class="text-center">
          Carols by Candlelight
        </h2>
        <p class="text-center">
          A candlelit service of traditional Christmas carols and readings,
          followed by refreshments.
        </p>
        <h3 class="text-center">
          Sunday 20 December, 6:00-7:00pm
        </h3>
      </div>
    </section>

    <section class="white-background pt-5">
      <div class="container">
        <h2 class="text-center">
          Christmas Day Celebration
        </h2>
        <p class="text-center">
          We meet together on Christmas day to joyfully celebrate the birth of our Saviour - the almighty God born as a baby in Bethlehem.
          Join us as we give glory to the new-born King.
        </p>
        <h3 class="text-center">
          Friday 25 December, 10:00-10:45am
        </h3>
      </div>
    </section>

  </main> -->

@stop
