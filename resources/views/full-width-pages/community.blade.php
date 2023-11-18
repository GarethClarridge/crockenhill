@extends('layouts.main')

@section('title')
  Community
@stop

@section('description')
  Community
@stop

@section('content')
  <main>

    <x-h1>
      Community
    </x-h1>

    <x-text>
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
    </x-text>

    <div class="grid grid-cols-1 gap-3 mx-12 my-6">
      <x-button link="#i-want-to-meet-local-people">
        I want to meet local people
      </x-button>
      <x-button link="#ive-got-children">
        I've got children
      </x-button>
      <x-button link="#i-want-to-find-out-more-about-jesus">
        I want to find out more about Jesus
      </x-button>
    </div>

    <x-h2>
      I want to meet local people
    </x-h2>

    <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">
          
      <x-page-card :page="$pages->firstWhere('slug', 'coffee-cup')" />

      <x-page-card :page="$pages->firstWhere('slug', 'baby-talk')" />
        
      <x-page-card :page="$pages->firstWhere('slug', 'sunday-services')" />
        
    </div>


    <x-h2>
      I've got children
    </x-h2>

    <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">
          
      <x-page-card :page="$pages->firstWhere('slug', 'baby-talk')" />
                
    </div>


    <x-h2> 
      I want to find out more about Jesus
    </x-h2>

    <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">
          
      <x-page-card :page="$pages->firstWhere('slug', 'sunday-services')" />

      <x-page-card :page="$pages->firstWhere('slug', 'christianity-explored')" />
        
      <x-page-card :page="$pages->firstWhere('slug', 'bible-study')" />
        
    </div>


    <x-h2>
      Occasional events
    </x-h2>

    <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">

      <x-page-card :page="$pages->firstWhere('slug', 'carols-in-the-chequers')" />
        
    </div>

  </main>

@stop
