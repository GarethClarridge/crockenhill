@extends('layouts.layer1')

@section('title', 'What We Believe - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="What We Believe - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('AboutUs', 'About Us') }} </li><li class="active">What We Believe</li>

@stop

@section('header', '<h1>What We Believe</h1>')

@section('main-content')

<p>Our Church fellowship is based on the fundamental truths of Christianity, as set out in the Bible. Here is a non-technical summary of its basic message. If you want to see some of the detail that lies behind this, or to check that this really does come from the Bible, click this link to view our <a href="statementoffaith">Statement of Faith</a>.</p>
	<h3>What We Believe:</h3>
	<ul>
		<li>This is God's world. He made it and is in charge of it. God has declared his Son, Jesus, to be Ruler over his world and we know this because Jesus rose again from the dead. Jesus has the right to run our lives.</li>
		<li>Everyone is rebellious to the fact that Jesus should run their lives. They may express this in open hostility, or by passive indifference. In either case, the rebellion is real.</li>
		<li>God calls on us to stop rebelling and to turn back and submit to Jesus as our rightful Ruler.</li>
		<li>If a person will not submit to Jesus but continues to rebel, in the end God will send that person to eternal punishment, because he or she has rejected God's appointed Ruler.</li>
		<li>If a person does stop rebelling and turns back to submit to Jesus as his or her Ruler, God forgives that person and treats them as if they had never rebelled. He does this because Jesus has already taken the punishment for their rebellion when he himself died on the cross.</li>
		<li>The Lord Jesus Christ will return to this world as Ruler. He will raise the dead and judge the world in righteousness. Those who have submitted to Jesus will be welcomed into a life of eternal joy in fellowship with God.</li>
	</ul>


@stop

@section('aside')

<h4>More Information:</h4>

<div class="list-group">
  <a href="{{ route('whatwebelieve') }}" class="list-group-item active">
    <h5 class="list-group-item-heading">What We Believe</h5>
    <small class="list-group-item-text">A short summary of our beliefs in everyday English. No technical terms here!</small>
  </a>
  <a href="{{ route('statementoffaith') }}" class="list-group-item">
    <h5 class="list-group-item-heading">Statement of Faith</h5>
    <small class="list-group-item-text">A more complete summary of our beliefs, giving Bible references and using slightly more technical language.</small>
  </a>
  <a href="{{ route('history') }}" class="list-group-item">
    <h5 class="list-group-item-heading">History</h5>
    <small class="list-group-item-text">Information about our church started.</small>
  </a>
    <a href="{{ route('pastor') }}" class="list-group-item">
    <h5 class="list-group-item-heading">Pastor</h5>
    <small class="list-group-item-text">Our pastor, Mark Drury.</small>
  </a>
</div>

@stop
