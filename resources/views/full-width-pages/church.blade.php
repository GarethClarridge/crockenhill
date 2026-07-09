@extends('layouts.main')

@section('title')
Church
@stop

@section('meta_description', 'Learn about Crockenhill Baptist Church - who we are, when we meet, and what kind of church we are. An independent evangelical Baptist church in Kent.')

@section('canonical')
<link rel="canonical" href="{{ url('/church') }}">
@endsection

@section('meta_tags')
<x-meta-tags
    title="Church"
    description="Learn about Crockenhill Baptist Church - who we are, when we meet, and what kind of church we are. An independent evangelical Baptist church in Kent."
    :image="asset('/images/homepage/may2024wide.webp')"
    image-alt="Crockenhill Baptist Church members outside the church building"
/>
<x-schema.webpage
    heading="Church"
    description="Learn about Crockenhill Baptist Church - who we are, when we meet, and what kind of church we are. An independent evangelical Baptist church in Kent."
    :image="asset('/images/homepage/may2024wide.webp')"
/>

<x-breadcrumbs area="church" heading="Church" jsonOnly />

<x-schema.faq :questions="[
    [
        'question' => 'When do we meet?',
        'answer' => 'We meet each Sunday morning at 10:30am for worship and Bible teaching, and again at 6:00pm for Bible teaching and prayer. During the week we meet in homes for Bible study.',
    ],
    [
        'question' => 'Who are we?',
        'answer' => 'We are a loving community of local people from many backgrounds and ages, united by our love for Jesus Christ and commitment to the Bible.',
    ],
    [
        'question' => 'What kind of church are we?',
        'answer' => 'We are an independent, evangelical church. This means we focus on the gospel: the good news that God has saved us by the death and resurrection of Jesus Christ.',
    ],
]" />
@stop

@section('content')
<main id="main-content" tabindex="-1" class="text-center">

  <x-h1>
    Church
  </x-h1>

  <x-text>
    <p>
      We're a family of people committed to living together under the authority of the Bible,
      who exist to worship God, strengthen believers and proclaim the good news of Jesus Christ to all.
    </p>
    <p>
      There are about 50 of us on a Sunday in person, with more
      following online. We're from different nationalities,
      backgrounds and ages from 5 to 85!
      The one thing that unites us is our love for Jesus Christ and
      our gratefulness for his amazing rescue.
    </p>
  </x-text>

  <div class="px-6 grid grid-cols-1 gap-6 max-w-2xl mx-auto mt-6">
    <x-button link="#when-do-we-meet">
      When do we meet?
    </x-button>
    <x-button link="#who-are-we">
      Who are we?
    </x-button>
    <x-button link="#what-kind-of-church-are-we">
      What kind of church are we?
    </x-button>
  </div>

  <x-h2>
    When do we meet?
  </x-h2>

  <x-text>
    <p>
      We meet together each Sunday morning at 10:30am to worship God,
      learn from the Bible, and enjoy fellowship with one another.
      We meet again on Sunday evenings
      for a service with a focus on prayer.
      We meet during the week in each other's homes for deeper Bible study,
      applying what God says to our lives.
    </p>
  </x-text>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 justify-center max-w-2xl lg:max-w-4xl mx-auto mt-6">

    <x-page-card :page="$pages->firstWhere('slug', 'sunday-mornings')" />

    <x-page-card :page="$pages->firstWhere('slug', 'sunday-evenings')" />

    <x-page-card :page="$pages->firstWhere('slug', 'bible-study')" />

  </div>

  <x-text>
    <p>
      We usually run groups for everyone from toddlers to pensioners
      so that we can tell as many people as possible the
      amazing news about Jesus and what he's done for us.
    </p>
  </x-text>

  <div class="px-6 max-w-2xl mx-auto mt-6">
    <x-button link="/community" wire:navigate>
      <div class="flex items-center justify-center">
        See our other activities
        <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" aria-hidden="true" />
      </div>
    </x-button>
  </div>

  <x-h2>
    Who are we?
  </x-h2>

  <x-h3>A loving community...</x-h3>

  <x-text>
    <p>
      Church is so much more than what takes place in organised
      groups and services. We want to be a church who support
      one another when times are tough, and who encourage
      one another to live the Christian life 24/7, to grow more
      and more like Jesus, and to show those we rub shoulders
      with every day something of the love of God.
    </p>
  </x-text>

  <x-h3>...of ordinary people...</x-h3>

  <x-text>
    <p>
      Our church is made up of people of all ages and backgrounds.
      In fact, people just like you! So whether you'd call yourself
      a Christian or you're just looking, we'd love to get to know you.
    </p>
    <p>
      We want people to become active followers of Jesus Christ,
      so as a church we provide opportunities for everyone
      to learn, serve and grow in their faith.
    </p>
  </x-text>

  <x-h3>...who have been saved by Jesus,...</x-h3>

  <x-text>
    <p>
      We know that we all naturally rebel against God. We want to
      live life our own way, putting ourselves first. But God
      promises that our way leads to destruction. Thankfully,
      in his infinite love for us, God sent his Son - Jesus - to
      rescue us from certain death.
    </p>
    <p>
      Jesus paid the penalty for our rebellion against God in his
      death on the cross, brought us back to a restored
      relationship with God our Father, and now lives
      in us by his Holy Spirit, guiding our lives day by day.
    </p>
  </x-text>

  <x-h3>...who take the Bible seriously,...</x-h3>

  <x-text>
    <p>
      If you come to our services you'll notice we spend a lot of
      time reading and explaining the Bible. We believe the Bible
      is God's perfect word to us - it's how he speaks into our
      everyday lives. When we hear the Bible preached, God speaks
      to our hearts by his Spirit and forms us to be more and more
      like Jesus.
    </p>
    <p>
      We want the Bible to inform everything we do. From our
      praying and singing on Sunday to working in the office on
      Monday. From bringing up our children to loving our friends.
    </p>
  </x-text>

  <x-h3>...and who want to tell everyone about Jesus.</x-h3>

  <x-text>
    <p>
      We know we've been given a wonderful gift by God, and we
      don't want to keep it to ourselves! Much of our energy as a
      church is spent on telling people the good news that they
      too can be rescued from death and brought into the eternal
      life of Christ.
    </p>

    <x-button link="/christ" wire:navigate>
      <div class="flex items-center justify-center">
        Find out more about the good news of Jesus Christ
        <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" aria-hidden="true" />
      </div>
    </x-button>
  </x-text>


  <x-h2>
    What kind of church are we?
  </x-h2>

  <x-h3>An <i>evangelical</i> church</x-h3>

  <x-text>
    <p>
      We believe that the gospel
      (<i>evangel</i> in Latin) is the
      good news of salvation to all people through Jesus Christ.
    </p>
    <p>
      We believe that the gospel centres on the death of Jesus
      Christ on the cross in our place.
    </p>
    <p>
      We believe that the gospel is revealed in the Bible,
      God's perfect word to us.
    </p>
    <p>
      For more detail on what we believe, see our
      <a href="/church/statement-of-faith" wire:navigate>
        statement of faith
      </a>.
    </p>
  </x-text>

  <x-h3>A <i>baptist</i> church</x-h3>

  <x-text>
    <p>
      Our church <a href="/church/history" wire:navigate>started life as "Union Chapel"</a>, a mixture
      of Baptists and Congregationalists.
    </p>

    <p>
      These days we're a Baptist church. That means we think the
      Bible teaches adults should be baptised (the word just means
      dunked in water) when they come to trust in Jesus Christ
      for salvation.
    </p>

    <p>
      Although adult baptism is our practice, we still have some
      members who think that children should also be baptised. We're
      happy to join together as a family working to reach
      people for Christ. We don't require members to be baptised as
      adults where they believe their infant baptism is biblical.
    </p>
  </x-text>

  <x-h3>An <i>independent</i> church</x-h3>

  <x-text>
    <p>
      Despite being a Baptist church, we're not a member of
      <a href="https://www.baptist.org.uk/">
        the Baptist Union
      </a>
      or
      <a href="https://www.gracebaptists.org/">
        the Association of Grace Baptist Churches
      </a>.
    </p>
    <p>
      In fact, we're not part of any denomination. We are run and
      entirely financially supported by our members: ordinary local
      people.
    </p>
    <p>
      We don't want to isolate ourselves from Christians in other
      places though. We are affiliated with
      <a href="https://fiec.org.uk/">
        the Fellowship of Independent Evangelical Churches
      </a>, a nationwide group of like-minded churches.
    </p>
  </x-text>

  <x-related-pages :links="$links ?? []" />

  <x-h2>
    Our policies
  </x-h2>

  <x-text>
    <p>
      In order to care for the members of the church and visitors to
      its activities, the church has adopted a number of policies:
    </p>
  </x-text>

  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 justify-items-center max-w-2xl lg:max-w-4xl mx-auto mt-6">
    <x-clickable-card link="/church/safeguarding-policy" heading="Safeguarding policy">
      Our safeguarding policy outlines how we work to keep
      children and vulnerable adults safe.
    </x-clickable-card>

    <x-clickable-card link="/media/documents/BehaviourPolicy.pdf" heading="Positive behaviour policy">
      Our positive behaviour policy guides how we make sure our
      children's groups are safe and fun for all involved.
    </x-clickable-card>

    <x-clickable-card link="/church/privacy-notice" heading="Privacy notice">
      Our privacy notice summarises how we use data and keep it safe.
    </x-clickable-card>

    <x-clickable-card link="/church/data-protection-policy" heading="Data protection policy">
      A detailed policy on using data and keeping it safe.
    </x-clickable-card>

    <x-clickable-card link="/church/information-security-policy" heading="Information security policy">
      How we store information safely.
    </x-clickable-card>

    <x-clickable-card link="/church/records-retention-policy" heading="Records retention policy">
      Our policy on retaining and destroying records.
    </x-clickable-card>

    <x-clickable-card link="/church/data-breach-policy" heading="Data breach policy">
      Our policy on what to do in the event of a data breach.
    </x-clickable-card>

    <x-clickable-card link="/church/data-protection-complaints-process" heading="Data protection complaints process">
      What to do if you have a complaint about our handling of your data.
    </x-clickable-card>
  </div>

</main>

@stop
