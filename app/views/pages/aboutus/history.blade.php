@extends('layouts.layer1')

@section('title', 'History - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="History of Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('AboutUs', 'About Us') }} </li><li class="active">History</li>

@stop

@section('header', '<h1>History of Crockenhill Baptist Church</h1>')

@section('main-content')

	<p>Helping people to find out the good news about Jesus Christ was the driving force behind the opening of Crockenhill's first Christian chapel back in 1801. Those pioneers took a serious look at village life and became convinced that their contemporaries were "sitting in darkness, and in the valley of the shadow of death" (that is how one magazine of the time described it).</p>
	<p>Those Christians, back at the beginning of the 19th century, decided that they couldn't simply do nothing. So they built a refuge where people could come out of the darkness and escape from the danger they saw them to be in.</p>
	<p>Those Christians did not think of themselves as being in any way superior to the rest of the villagers - any more than a life-boat man feels superior to the person he rescues from drowning. But if you yourself have experienced a great rescue, would you leave others in danger, when they could also be brought to safety?</p>
	<p>It was a serious reading of the Bible which convinced those chapel-builders of the darkness and danger we all face without Jesus Christ. It is the same ever-relevant Bible that convinces us to continue their work.</p>


@stop

@section('aside')

<h4>More Information:</h4>

<div class="list-group">
  <a href="{{ route('whatwebelieve') }}" class="list-group-item">
    <h5 class="list-group-item-heading">What We Believe</h5>
    <small class="list-group-item-text">A short summary of our beliefs in everyday English. No technical terms here!</small>
  </a>
  <a href="{{ route('statementoffaith') }}" class="list-group-item">
    <h5 class="list-group-item-heading">Statement of Faith</h5>
    <small class="list-group-item-text">A more complete summary of our beliefs, giving Bible references and using slightly more technical language.</small>
  </a>
  <a href="{{ route('history') }}" class="list-group-item active">
    <h5 class="list-group-item-heading">History</h5>
    <small class="list-group-item-text">Information about our church started.</small>
  </a>
    <a href="{{ route('pastor') }}" class="list-group-item">
    <h5 class="list-group-item-heading">Pastor</h5>
    <small class="list-group-item-text">Our pastor, Mark Drury.</small>
  </a>
</div>

@stop
