@extends('layouts.main')

@section('title', 'Free Bibles')

@section('meta_description', 'Would you like a free Bible? Crockenhill Baptist Church is offering free Bibles to anyone in the Crockenhill or Chelsfield area — completely free, no strings attached.')

@section('meta_tags')
<x-meta-tags
  title="Free Bibles"
  description="Would you like a free Bible? Crockenhill Baptist Church is offering free Bibles to anyone in the Crockenhill or Chelsfield area — completely free, no strings attached." />
<x-schema.webpage
  heading="Free Bibles"
  description="Would you like a free Bible? Crockenhill Baptist Church is offering free Bibles to anyone in the Crockenhill or Chelsfield area — completely free, no strings attached."
/>

<x-breadcrumbs area="christ" heading="Free Bibles" jsonOnly />

<x-schema.faq :questions="[
    [
        'question' => 'Can I really get a free Bible?',
        'answer' => 'Yes — completely free, no strings attached. We just ask that you give us your address so we can post or deliver it to you.',
    ],
    [
        'question' => 'Who can request a Bible?',
        'answer' => 'Anyone who lives in or near Crockenhill or Chelsfield in Kent is welcome to request a free Bible.',
    ],
    [
        'question' => 'Which Bible translation will I receive?',
        'answer' => 'We normally give out the New International Version (NIV), which is a widely used modern English translation.',
    ],
    [
        'question' => 'Why are you giving away free Bibles?',
        'answer' => 'We believe the Bible is the most important book ever written, and we want everyone in our community to have the chance to read it for themselves.',
    ],
]" />
@stop

@section('content')
<main id="main-content" class="text-center">

  <x-h1>
    Free Bibles
  </x-h1>

  <x-text>
    <p>
      We believe the Bible is the most important book ever written —
      and we'd love to give you one, completely free.
    </p>
    <p>
      If you live in the Crockenhill or Chelsfield area and would like
      a Bible, just fill in the form below. We'll post or deliver it to
      you — no cost, no catch, no obligation.
    </p>
    <p>
      If you're not sure where to start reading, we'd suggest
      <a href="https://www.biblegateway.com/passage/?search=Mark+1&version=NIVUK">
        Mark's gospel
      </a> — a short, fast-paced account of the life of Jesus.
    </p>
  </x-text>

  <x-h2>
    Request your free Bible
  </x-h2>

  <div class="mx-auto max-w-lg px-6 text-left">
    <livewire:christ.bible-request-form />
  </div>

  <x-h2>
    What is the Bible?
  </x-h2>

  <x-text>
    <p>
      The Bible is a collection of 66 books written over more than a
      thousand years, yet with a single consistent message: God made
      the world, we have rejected him, but he sent Jesus Christ to
      rescue us.
    </p>
    <p>
      Whatever your background — whether you've never opened a Bible,
      or haven't read one since school — we'd encourage you to give it
      another look. Many people are surprised by how relevant and
      readable it is.
    </p>
    <p>
      You can also read the Bible online at
      <a href="https://www.biblegateway.com">BibleGateway</a>
      if you'd prefer not to wait for a physical copy.
    </p>
  </x-text>

</main>
@stop
