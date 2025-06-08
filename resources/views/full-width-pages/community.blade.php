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

    @php
        $pageForCard_coffee_cup = $pages->firstWhere('slug', 'coffee-cup');
    @endphp
    @if ($pageForCard_coffee_cup)
        <x-page-card :page="$pageForCard_coffee_cup" />
    @endif

    @php
        $pageForCard_baby_talk_1 = $pages->firstWhere('slug', 'baby-talk');
    @endphp
    @if ($pageForCard_baby_talk_1)
        <x-page-card :page="$pageForCard_baby_talk_1" />
    @endif

    @php
        $pageForCard_sunday_mornings_1 = $pages->firstWhere('slug', 'sunday-mornings');
    @endphp
    @if ($pageForCard_sunday_mornings_1)
        <x-page-card :page="$pageForCard_sunday_mornings_1" />
    @endif

  </div>


  <x-h2>
    I've got children
  </x-h2>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">

    @php
        $pageForCard_baby_talk_2 = $pages->firstWhere('slug', 'baby-talk');
    @endphp
    @if ($pageForCard_baby_talk_2)
        <x-page-card :page="$pageForCard_baby_talk_2" />
    @endif

    @php
        $pageForCard_family_talk = $pages->firstWhere('slug', 'family-talk');
    @endphp
    @if ($pageForCard_family_talk)
        <x-page-card :page="$pageForCard_family_talk" />
    @endif

    @php
        $pageForCard_buzz_club = $pages->firstWhere('slug', 'buzz-club');
    @endphp
    @if ($pageForCard_buzz_club)
        <x-page-card :page="$pageForCard_buzz_club" />
    @endif

  </div>


  <x-h2>
    I want to find out more about Jesus
  </x-h2>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">

    @php
        $pageForCard_sunday_mornings_2 = $pages->firstWhere('slug', 'sunday-mornings');
    @endphp
    @if ($pageForCard_sunday_mornings_2)
        <x-page-card :page="$pageForCard_sunday_mornings_2" />
    @endif

    @php
        $pageForCard_christianity_explored = $pages->firstWhere('slug', 'christianity-explored');
    @endphp
    @if ($pageForCard_christianity_explored)
        <x-page-card :page="$pageForCard_christianity_explored" />
    @endif

    @php
        $pageForCard_bible_study = $pages->firstWhere('slug', 'bible-study');
    @endphp
    @if ($pageForCard_bible_study)
        <x-page-card :page="$pageForCard_bible_study" />
    @endif

  </div>


  <x-h2>
    Occasional events
  </x-h2>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 justify-center max-w-2xl lg:max-w-5xl mx-auto mt-6">

    @php
        $pageForCard_carols_in_the_chequers = $pages->firstWhere('slug', 'carols-in-the-chequers');
    @endphp
    @if ($pageForCard_carols_in_the_chequers)
        <x-page-card :page="$pageForCard_carols_in_the_chequers" />
    @endif

  </div>

</main>

@stop