@extends('layouts.main')

@section('title', 'Christ')

@section('meta_description', 'Learn about Jesus Christ: who he is, why you need him, and what he has done for you. Explore the good news of Jesus at Crockenhill Baptist Church.')

@section('canonical')
<link rel="canonical" href="{{ url('/christ') }}">
@endsection

@section('meta_tags')
<x-meta-tags
  title="Christ"
  description="Learn about Jesus Christ: who he is, why you need him, and what he has done for you. Explore the good news of Jesus at Crockenhill Baptist Church."
  :image="asset('/images/homepage/may2024wide.webp')"
  image-alt="Crockenhill Baptist Church members outside the church building" />
<x-schema.webpage
  heading="Christ"
  description="Learn about Jesus Christ: who he is, why you need him, and what he has done for you. Explore the good news of Jesus at Crockenhill Baptist Church."
  :image="asset('/images/homepage/may2024wide.webp')"
/>

{{-- VideoObject JSON-LD for the featured gospel video --}}
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'VideoObject',
    'name' => 'Two Ways to Live - The Gospel in 90 seconds',
    'description' => 'A short animation explaining the core message of Christianity: how we can be reconciled to God through Jesus Christ.',
    'thumbnailUrl' => 'https://i.ytimg.com/vi/Y_lDeIzh2t0/maxresdefault.jpg',
    'uploadDate' => '2012-05-24T10:14:52Z',
    'duration' => 'PT1M27S',
    'contentUrl' => 'https://www.youtube.com/watch?v=Y_lDeIzh2t0',
    'embedUrl' => 'https://www.youtube.com/embed/Y_lDeIzh2t0',
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'Matthias Media',
        'url' => 'https://matthiasmedia.com.au/'
    ]
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>

<x-schema.faq :questions="[
    [
        'question' => 'Who is Jesus?',
        'answer' => 'The Lord Jesus Christ is fully God and fully man. He was conceived by the Holy Spirit, born of a virgin, and lived a sinless life in obedience to the Father. He taught with authority and all his words are true. On the cross he died in the place of sinners, bearing God\'s punishment for their sin, redeeming them by his blood. He rose from the dead and in his resurrection body ascended into heaven where he is exalted as Lord of all. He intercedes for his people in the presence of the Father.',
    ],
    [
        'question' => 'Why do I need Jesus?',
        'answer' => 'We have all rejected God\'s authority and chosen to live our own way, leading to broken relationships, guilt, and eventually eternal separation from God. We need Jesus to be reconciled to God and receive eternal life.',
    ],
    [
        'question' => 'What has Jesus done for me?',
        'answer' => 'Jesus lived the perfect life we failed to live and died on the cross to take the penalty for our rebellion. By rising from the dead three days later, he defeated death entirely, offering us forgiveness and eternal life.',
    ],
    [
        'question' => 'Where can I find out more?',
        'answer' => 'You can come along, get in touch with our pastor, or start reading Mark\'s gospel in the Bible to decide for yourself who Jesus is. If you need a Bible, we\'d love to give you one free — visit our Free Bible page.',
    ],
]" />
@stop

@push('breadcrumb_schema')
<x-breadcrumbs area="christ" heading="Christ" jsonOnly />
@endpush

@section('content')
<main id="main-content" tabindex="-1" class="text-center">

  <x-h1>
    Christ
  </x-h1>

  <x-text>
    <p>
      We want everything we do at Crockenhill Baptist Church to
      centre on Jesus Christ.
    </p>
    <p>
      Christ has saved us from death and judgement, and brought us
      into life and joy. We worship him, want to lead lives that
      honour him, and long to tell others about him so they too may
      receive life in him.
    </p>
    <p>
      This video shows how Christianity is all about Christ:
    </p>
  </x-text>

  <x-youtube
    link="https://www.youtube.com/embed/Y_lDeIzh2t0"
    title="Two Ways to Live - The Gospel in 90 seconds" />

  <x-button-grid>
    <x-button link="#who-is-jesus">
      Who is Jesus?
    </x-button>
    <x-button link="#why-do-i-need-jesus">
      Why do I need Jesus?
    </x-button>
    <x-button link="#what-has-jesus-done-for-me">
      What has Jesus done for me?
    </x-button>
    <x-button link="#where-can-i-find-out-more">
      Where can I find out more?
    </x-button>
    <x-button link="#get-a-free-bible">
      Get a free Bible
    </x-button>
  </x-button-grid>

  <x-h2>
    Who is Jesus?
  </x-h2>

  <x-text>
    <p>
      Jesus Christ is by far the most influential person in history.
    </p>
    <p>
      But how did a man born the son of a carpenter in a village
      smaller than Crockenhill, in a province on the edge of the
      Roman Empire, end up shaping the course of history, the
      culture of nations, and the lives of up to 2 billion people?
    </p>
    <p>
      Obviously he must be more than just a carpenter's son. Jesus
      proved that in his life. He healed the sick, he calmed the
      storm, he raised the dead to life. He taught with power and
      authority that had never been seen before.
    </p>
    <p>
      As the video above says, by doing these
      things Jesus was showing us that he is God's King. The one
      with supreme authority over the universe: God himself in
      human form. Jesus was showing us he was the one
      God had promised to send to sort out this mess of a world we
      all live in, and to rule it with justice for all.
    </p>
    <p>
      Isn't that what we all want? Jesus showed us a
      glimpse of a world without sickness and death. A
      world without injustice and pain. Wouldn't that be great?
    </p>
  </x-text>

  <x-h2>
    Why do I need Jesus?
  </x-h2>

  <x-text>
    <p>
      But <a href="#who-is-jesus">the world Jesus gave us a glimpse of</a>
      isn't the one we have, is it?
    </p>
    <p>
      Our world is full of sickness and pain, loneliness and
      suffering, injustice and oppression.
    </p>
    <p>
      It's obvious we've all rejected God's king. We don't
      want to live as God says, we want to do our own thing. And
      all around us we see the results.
    </p>
    <p>
      Sometimes when people see something particularly shocking
      they say: "he needs Jesus!". But deep down we know we
      all need help to be who we want to be. We know
      we're not perfect. Our relationships are broken: we hurt
      those we love. We feel guilty: we don't even live up
      to our own standards.
    </p>
    <p>
      We've rejected Jesus and separated ourselves from him.
      One day that will be permanent. God will banish all those
      who reject his King, and they'll be left in darkness without
      anything good forever. Jesus called this hell, and spent
      much of his life warning us about it.
    </p>
    <p>
      The only way out is to be reconciled with Jesus, God's king.
      But how?
    </p>
  </x-text>

  <x-h2>
    What has Jesus done for me?
  </x-h2>

  <x-text>
    <p>
      God saw <a href="#why-do-i-need-jesus">we'd rebelled against him</a>, saw we were
      headed for a lost eternity without him, and he loved us
      enough to do something about it.
    </p>
    <p>
      Jesus was born as a man and lived a perfect life - the life
      we failed to live. And having proved himself the
      perfect man, he was killed. Murdered in the most barbaric
      way possible.
    </p>
    <p>
      But this wasn't a mistake! It was all part of the plan.
      Jesus became a man to die on our behalf. To take
      the death we deserved and suffer it himself. And having
      suffered death, to defeat it entirely.
    </p>
    <p>
      Three days after dying on the cross, Jesus rose from the
      grave. He was seen by hundreds of eye-witnesses. And in
      rising from the dead Jesus proved that death had no power
      over him.
    </p>
    <p>
      Because Jesus rose from the dead, our guilt doesn't need to
      have any more hold on us. Jesus bore the penalty on our
      behalf. Nothing stands between us and God anymore. We can be
      reconciled. Instead of eternal death, Jesus offers eternal
      life!
    </p>
    <p>
      No wonder we say that the gospel is the <i>good news</i> of
      Jesus Christ!
    </p>
  </x-text>

  <x-h2>
    Where can I find out more?
  </x-h2>

  <x-text>
    <p>
      If you'd like to hear more about the good news of Jesus Christ,
      why not get in touch with Mark, our
      pastor, using the details below. He'd be more than happy to
      discuss whatever questions you might have.
    </p>
    <p>
      If you'd rather research on your own, you can find out
      more about this good news at the
      <a href="https://www.christianityexplored.org/">
        Christianity Explored website
      </a>. They also have answers to tough questions and
      stories of real people whose lives have been changed when they
      came to know Jesus for themselves.
    </p>
    <p>
      A great place to start is to read through one of the accounts
      of Jesus' life in the Bible so you can decide for
      yourself what you think. We'd recommend starting with
      <a href="https://www.biblegateway.com/passage/?search=Mark+1&version=NIVUK">
        Mark's gospel on BibleGateway
      </a>. If you don't have a Bible and would like one, see below.
    </p>
  </x-text>

  <x-h2 id="get-a-free-bible">
    Get a free Bible
  </x-h2>

  <x-text>
    <p>
      If you live in the Crockenhill or Chelsfield area and would like
      a free Bible, we'd love to give you one — no strings attached.
    </p>
  </x-text>

  <div class="mx-auto w-full max-w-[34rem] px-6 text-center">
    <div class="w-full rounded-xl bg-gradient-teal p-[1.5px]">
      <x-button link="/christ/free-bible" wire:navigate variant="featureOutline" size="lg" class="w-full rounded-[11px]">
        Request a free Bible
      </x-button>
    </div>
  </div>

</main>

@stop
