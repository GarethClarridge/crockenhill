@extends('layouts.main')

@section('title')
Community
@stop

@section('meta_description', 'Join our community activities at Crockenhill Baptist Church. Meet local people, activities for children, and opportunities to learn about Jesus in Kent.')

@section('canonical')
<link rel="canonical" href="{{ url('/community') }}">
@endsection

@section('meta_tags')
<x-meta-tags
  title="Community"
  description="Join our community activities at Crockenhill Baptist Church. Meet local people, activities for children, and opportunities to learn about Jesus in Kent."
  :image="asset('/images/homepage/may2024wide.webp')"
  image-alt="Crockenhill Baptist Church members outside the church building" />
<x-schema.webpage
  heading="Community"
  description="Join our community activities at Crockenhill Baptist Church. Meet local people, activities for children, and opportunities to learn about Jesus in Kent."
  :image="asset('/images/homepage/may2024wide.webp')"
/>

<x-breadcrumbs area="community" heading="Community" jsonOnly />

<x-schema.faq :questions="[
    [
        'question' => 'How can I meet local people in Crockenhill?',
        'answer' => 'We host several regular activities open to everyone in the community, including our weekly Coffee Cup on Thursday mornings, Baby Talk for parents and toddlers on Monday mornings, and our Sunday morning services at 10:30am.',
    ],
    [
        'question' => 'What activities are available for children?',
        'answer' => 'We have groups for all ages, including Baby Talk (parents and toddlers), Family Talk (monthly Sunday afternoon activity), and Buzz Club (our weekly group for primary school children on Friday evenings).',
    ],
    [
        'question' => 'How can I find out more about Jesus?',
        'answer' => 'You are welcome to join us on Sunday mornings at 10:30am, or you might be interested in our Christianity Explored course, which is a relaxed way to find out more about the good news of Jesus Christ.',
    ],
]" />
@stop

@section('content')
<main id="main-content" tabindex="-1" class="text-center">

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
      <a href="/christ" wire:navigate>the good news about Jesus Christ</a> known
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

  <div class="px-6 grid grid-cols-1 gap-6 max-w-2xl mx-auto mt-6">
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

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 justify-center max-w-2xl lg:max-w-4xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'coffee-cup')" />

    <x-page-card :page="$pages->firstWhere('slug', 'baby-talk')" />

    <x-page-card :page="$pages->firstWhere('slug', 'sunday-mornings')" />

  </div>


  <x-h2>
    I've got children
  </x-h2>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 justify-center max-w-2xl lg:max-w-4xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'baby-talk')" />

    <x-page-card :page="$pages->firstWhere('slug', 'family-talk')" />

    <x-page-card :page="$pages->firstWhere('slug', 'buzz-club')" />

  </div>


  <x-h2>
    I want to find out more about Jesus
  </x-h2>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 justify-center max-w-2xl lg:max-w-4xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'sunday-mornings')" />

    <x-page-card :page="$pages->firstWhere('slug', 'christianity-explored')" />

    <x-page-card :page="$pages->firstWhere('slug', 'bible-study')" />

  </div>


  <x-h2>
    Occasional events
  </x-h2>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 justify-center max-w-2xl lg:max-w-4xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'carols-in-the-chequers')" />

  </div>

</main>

@stop