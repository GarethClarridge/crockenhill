@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('description', '<meta name="description" content="We are an independent evangelical church located in the village of Crockenhill in Kent.">')

@section('content')

  <main id="home" class="text-sm">

    <div class="full-width-head py-16 mb-16" style="background-image: linear-gradient( rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4) ), url({{ asset('/images/homepage/Collage.jpg')}})">
      <div class="grid grid-cols-1 md:grid-cols-2 mx-auto justify-items-center">
        <h1 class="text-white text-6xl font-display p-12 text-center md:text-left">
          Crockenhill<br>
          Baptist<br>
          Church.<br>
        </h1>

        <div class="p-3 leading-10 text-xl self-center font-display text-center md:text-right">
          <p class="my-2">
            <a href="#worshipping-god">
              <span class="px-2 py-1 bg-white">
                Worshipping God
              </span>
            </a>
          </p>
          <p class="my-2">
            <a href="#strengthening-believers">
              <span class="px-2 py-1 bg-white">
                Strengthening believers
              </span>
            </a>
          </p>
          <p class="my-2">
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

      <p class="">
        We meet to worship God and hear from his Word on Sundays at 10:30am.
        You're more than welcome to join us - we'd love to see you!
      </p>
    </x-text>

    <x-h2>
      Worshipping God
    </x-h2>

   <x-text>
      <p class="">
        We meet to worship God together as a church every Sunday at 10:30am.
        Services involve reading the Bible, praying, singing
        and hearing God's word preached.
      </p>
      <p class="">
        If you can't make it in person you can watch our services on
        <a class="inline" href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">
          our YouTube channel
        </a> at 10:30am on Sundays.
      </p>
    </x-text>
    

    <x-youtube 
      link="https://www.youtube.com/embed/2uuZ0amyWFE" 
      title="Sunday 5th November 2023"
    />

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

      <x-page-card :page="$pages->firstWhere('slug', 'sunday-services')" />
        
      <x-page-card :page="$pages->firstWhere('slug', 'bible-study')" />

    </div>


    <x-h2>
      Proclaiming Jesus Christ to all
    </x-h2>

   <x-text>
      <p class="">
        We're eager to tell people about Jesus.
        After all, Christianity is good news:
      </p>
    </x-text>

    <x-youtube 
      link="https://www.youtube.com/embed/Ue3rHGDMzjU" 
      title="Good News in 90 Seconds"
    />

    <div class="mx-6">
      <x-button link="/christ">
        Find out more about this good news
        <i class="ml-1 fas fa-arrow-circle-right"></i>
      </x-button>
    </div>
    
    <x-text>
      <p class="">
        If you've got questions, do get in touch using the details below.
        We'd love to listen.
      </p>
    </x-text>


  </main>

@stop
