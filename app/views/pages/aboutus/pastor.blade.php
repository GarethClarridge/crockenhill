@extends('layouts.layer1')

@section('title', 'Pastor - Crockenhill Baptist Church')

@section('description', '<meta name="description" content="Pastor - Crockenhill Baptist Church">')

@section('breadcrumbs')

<li> {{ link_to_route('AboutUs', 'About Us') }} </li><li class="active">Pastor</li>

@stop

@section('header', '<h1>Pastor Mark Drury</h1>')

@section('main-content')

<p>Our Pastor, Mark Drury, originates from Suffolk.  He obtained a degree in Applied Theology at Moorlands Bible College before becoming trainee pastor at Beccles Baptist Church in Suffolk.  He joined us in Crockenhill in 2011, but before this he served in a number of churches in different roles in Essex and Suffolk.  He is married to Joanne and they have a daughter called Naomi.  Mark enjoys reading, seeing Ipswich Town win at football, skiing, sport, decorating, gardening and spending quality time with his family.</p>
 
<p>He can be reached by phone on 01322 663995, or by email at {{HTML::mailto('pastor@crockenhill.org', 'pastor@crockenhill.org')}}.</p>


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
  <a href="{{ route('history') }}" class="list-group-item">
    <h5 class="list-group-item-heading">History</h5>
    <small class="list-group-item-text">Information about our church started.</small>
  </a>
    <a href="{{ route('pastor') }}" class="list-group-item active">
    <h5 class="list-group-item-heading">Pastor</h5>
    <small class="list-group-item-text">Our pastor, Mark Drury.</small>
  </a>
</div>

@stop
