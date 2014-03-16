@extends('layouts.main')

@section('title', 'Crockenhill Baptist Church')

@section('description', '<meta name="description" content="We are an independent evangelical church located in the village of Crockenhill in Kent.">')

@section('content')

    <main>
        <div class="home-head">
            <div class="container">
                <h1 class="text-center">Crockenhill Baptist Church</h1>
                <p class="text-center lead">A friendly, Bible believing church just outside Swanley.</p>
            </div>
        </div>

        <div class="home-body">
            <div class="container">
                <div id="container" class="js-masonry" data-masonry-options='{"itemSelector": ".home-block"}'>
                    <div class="home-block-list">
                        <a href="{{-- route('AboutUs') --}}" class="home-block">
                            <section class="media">
                                <div class="pull-left glyphicon glyphicon-user media-object"></div>
                                <div class="media-body">
                                    <h3 class="media-heading">Who?</h2>

                                	<p>Although the church exists worldwide, we are a local expression of the world-wide family of God’s people in Crockenhill – and have been for over 200 years!</p>
                            	</div>
                            </section>
                        </a>

                        <a href="{{-- route('WhatsOn') --}}" class="home-block">
                            <section class="media">
                                <div class="pull-left glyphicon glyphicon-calendar media-object"></div>
                                <div class="media-body">
                                    <h3 class="media-heading">What?</h2>

                                    <p>There are many things on at Crockenhill Baptist Church. The main meetings are our Sunday services, but we also have many other groups. We have groups for children and young people, bible study and prayer groups, a men's group, and many more.</p>
                                </div>
                            </section>
                        </a>

                        <a href="{{-- route('Where') --}}" class="home-block">               
                            <section class="media">
                                <div class="pull-left glyphicon glyphicon-home media-object"></div>
                                <div class="media-body">
                                    <h3 class="media-heading">Where?</h2>

                                    <p>We are located in the village of Crockenhill in Kent, which is a mile or so south of Swanley. If you are travelling from a distance, we are just off junction 3 of the M25. </p>
                                </div>
                            </section>
                        </a>

                        <a href="{{-- route('sunday') --}}" class="home-block">
                            <section class="media">
                                <div class="pull-left glyphicon glyphicon-time media-object"></div>
                                <div class="media-body">
                                    <h3 class="media-heading">When?</h2>

                                    <p>Our main church gatherings are on Sundays. Our morning worship service starts at 10:30 a.m. and includes those of all ages. The evening meeting starts at 6:30 p.m., where a different sermon is preached. Once a month we have an all-age service at 5:00 p.m. During the week we meet for Bible study and prayer in smaller groups.</p>
                                </div>
                            </section>
                        </a>

                        <a href="{{-- route('AboutUs') --}}" class="home-block">
                            <section class="media">
                                <div class="pull-left glyphicon glyphicon-question-sign media-object"></div>
                                <div class="media-body">
                                    <h3 class="media-heading">Why?</h2>

                            	    <p>Our church exists to worship God, strengthen believers in their faith, and to proclaim the good news of Christianity to all, so that others might experience the wonderful salvation of God through faith in Jesus Christ. We are far from being a perfect church, but can testify to God’s goodness and power in helping us to live for him.</p>
                        	    </div>
                            </section>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

@stop