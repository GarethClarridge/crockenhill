@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('description', '
<meta name="description" content="We are an independent evangelical church located in the village of Crockenhill in Kent.">')

@section('content')

<main id="home" class="text-sm">

  <div class="full-width-head py-16 mb-16" style="background-image: linear-gradient( rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4) ), url({{ asset('/images/homepage/Collage.webp')}})">
    <div class="grid grid-cols-1 md:grid-cols-2 mx-auto justify-items-center">
      <h1 class="text-white text-6xl font-display p-12 text-center md:text-left">
        Crockenhill<br>
        Baptist<br>
        Church.<br>
      </h1>

      <div class="p-3 leading-10 text-xl self-center font-display text-center md:text-right">
        <p class="hover:scale-110 my-2">
          <a href="#worshipping-god">
            <span class="px-2 py-1 bg-white ">
              Worshipping God
            </span>
          </a>
        </p>
        <p class="hover:scale-110 my-2">
          <a href="#strengthening-believers">
            <span class="px-2 py-1 bg-white">
              Strengthening believers
            </span>
          </a>
        </p>
        <p class="hover:scale-110 my-2">
          <a href="#proclaiming-jesus-christ-to-all">
            <span class="px-2 py-1 bg-white">
              Proclaiming Jesus Christ to all
            </span>
          </a>
        </p>
      </div>
    </div>

  </div>

  <x-text>
    <p class="">
      Crockenhill Baptist Church is friendly, Bible teaching church in
      the village of Crockenhill, just outside Swanley.
    </p>

  </x-text>

  <x-h2>
    Worshipping God
  </x-h2>

  <x-text>
    <p class="">
      We meet to worship God together as a church every Sunday at 10:30am.
      Services involve reading the Bible, praying, singing
      and hearing God's word preached. We also meet on Sunday evenings at
      6pm for a service mostly focussed on prayer.
    </p>
    <p class="">
      You're more than welcome to join us - we'd love to see you! If you can't make it in person you can watch our morning services on
      <a class="inline" href="https://www.youtube.com/@crockenhillbaptistchurch9727/streams">
        our YouTube channel
      </a> at 10:30am on Sundays.
    </p>
  </x-text>

  <!-- <x-youtube 
      link="https://www.youtube.com/embed?listType=playlist&list=UUtSUTtkZlALToswWQpWS2kA" 
      title=""
    /> -->

  <!-- <section class="-mb-10 bg-cover bg-center bg-[url('/public/images/homepage/christmas2023.jpg')] bg-gray-700 bg-blend-multiply">
        <div class="px-4 mx-auto max-w-screen-xl text-center pt-24 pb-12">
            <h2 class="mb-20 text-4xl font-display leading-none text-white md:text-5xl lg:text-6xl">
              Christmas at Crockenhill Baptist Church
            </h2>
            <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
              Comfort and Joy
            </h3>
            <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
              Saturday 2nd, 3-6pm
            </p>
            <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
              Family Talk
            </h3>
            <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
              Sunday 10th, 4-6pm
            </p>
            <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
              Coffee Cup Carols
            </h3>
            <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
              Thursday 14th, 10:30am
            </p>
            <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
              Carols in the Chequers
            </h3>
            <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
              Wednesday 20th, 7:30pm
            </p>
            <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
              Carols by Candlelight
            </h3>
            <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
              Sunday 24th, 6:00pm
            </p>
            <h3 class="font-display mt-8 text-xl text-white lg:text-2xl sm:px-16 lg:px-48">
              Christmas Day Family Service
            </h3>
            <p class="mb-8 text-lg font-normal text-white lg:text-xl sm:px-16 lg:px-48">
              Monday 25th, 10:30am
            </p>
        </div>
    </section> -->

  <x-h2>
    Strengthening believers
  </x-h2>

  <x-text>
    <p class="">
      We want to continue to grow in our faith: becoming closer to God
      as the Holy Spirit makes us more like Jesus.
    </p>
  </x-text>

  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 justify-center max-w-2xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'sunday-evenings')" />

    <x-page-card :page="$pages->firstWhere('slug', 'bible-study')" />

  </div>


  <x-h2>
    Proclaiming Jesus Christ to all
  </x-h2>

  <x-text>
    <p class="">
      We're eager to tell people about Jesus.
      After all, Christianity is good news!
    </p>
  </x-text>

  <!-- <x-youtube link="https://www.youtube.com/embed/Ue3rHGDMzjU" title="Good News in 90 Seconds" /> -->

  <div class="m-12">
    <x-button link="/christ">
      <div class="flex items-center justify-center">
        Find out more about this good news
        <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" />
      </div>
    </x-button>
  </div>

  <x-text>
    <p class="my-6">
      If you've got questions, do get in touch using the details below.
      We'd love to hear from you.
    </p>
  </x-text>


</main>

@stop