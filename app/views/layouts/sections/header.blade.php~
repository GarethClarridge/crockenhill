<?php

$pages = array(
    'AboutUs' => array('route'=> 'AboutUs', 'name' => 'About Us'),
    'WhatsOn' => array('route'=> 'WhatsOn', 'name' => 'What\'s On'),
    'Where' => array('route'=> 'Where', 'name' => 'Where'),
    'ContactUs' => array('route' => 'ContactUs', 'name' => 'Contact Us'),
    'Sermons' =>array('route'=> 'Sermons', 'name' => 'Sermons'),
    'Publications' => array('route'=> 'Publications', 'name' => 'Publications'),
    'Links' => array('route'=> 'Links', 'name' => 'Links'),
);

?>

<nav class="navbar navbar-inverse navbar-fixed-top" role="navigation">
    <div class="navbar-inner">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
	        <a class="navbar-brand logo" href=" {{ URL::route('Home') }} ">Crockenhill Baptist Church</a>
        </div>
        <div class="navbar-collapse collapse">
            <ul class="nav navbar-nav">

                @foreach ($pages as $page)
		            @if (Request::is($page['route']))
		                <li class="active">
		            @else
		                <li>
		            @endif
		                <a href=" {{ URL::route($page['route']) }} ">{{$page['name']}}</a>
		            </li>
	            @endforeach

            </ul>
            <ul class="nav navbar-nav pull-right">
	            @if (Request::is('Members'))
	                <li class="active">
	            @else
	                <li>
	            @endif
	                <a href=" {{ URL::route('Members') }} ">Members</a>
	            </li>
        </div>
	</div>
</nav>
