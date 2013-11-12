<div class="row">
    <section class="col-sm-4">

        <h4 class="text-center">Latest Sermons</h4>

        <div class="row">
            <div class="col-md-6">

                <h5>Morning</h5>

                <p>
                    {{ link_to_route('sermon', '"'.$latest_morning_sermon->title.'"', $parameters = array($latest_morning_sermon->filename))}}
                </p>
                <p>
                    <span class="glyphicon glyphicon-calendar"></span> &nbsp
                    {{ date ('jS \of F', strtotime($latest_morning_sermon->date)) }}
                </p>
                <p>
                    <span class="glyphicon glyphicon-user"></span> &nbsp
                    {{ $latest_morning_sermon->preacher }}
                </p>
                <p>
                    <span class="glyphicon glyphicon-book"></span> &nbsp
                    {{ $latest_morning_sermon->reference }}
                </p>

            </div>
            <div class="col-md-6">

                <h5>Evening</h5>

                <p>
                    {{ link_to_route('sermon', '"'.$latest_evening_sermon->title.'"', $parameters = array($latest_evening_sermon->filename))}}
                </p>
                <p>
                    <span class="glyphicon glyphicon-calendar"></span> &nbsp
                    {{ date ('jS \of F', strtotime($latest_evening_sermon->date)) }}
                </p>
                <p>
                    <span class="glyphicon glyphicon-user"></span> &nbsp
                    {{ $latest_evening_sermon->preacher }}
                </p>
                <p>
                    <span class="glyphicon glyphicon-book"></span> &nbsp
                    {{ $latest_evening_sermon->reference }}
                </p>

            </div>
        </div>

    </section>
    <section class="col-sm-4">
        <h4 class="text-center">Contact our pastor</h4>
        <div class="media">
            <img src=" {{ asset('images/mark-whitecircle.png') }} " alt="Mark Drury" class="mark-icon media-object pull-left"/>
            <div class="media-body">
                <br>
                <p><span class="pull-left glyphicon glyphicon-earphone"></span> &nbsp&nbsp 01322 663995</p>
                <p><span class="pull-left glyphicon glyphicon-envelope"></span> &nbsp&nbsp {{HTML::mailto('pastor@crockenhill.org', 'pastor@crockenhill.org')}}</span></p>
            </div>
        </div>
    </section>
    <section class="col-sm-4">
        <h4 class="text-center">Address</h4>
        <div class="row">
            <div class="col-xs-1">
                <span class="pull-left glyphicon glyphicon-home"></span>
            </div>
            <div class="col-xs-11">
                <address>
                    Crockenhill Baptist Church<br />
                    Eynsford Road<br />
                    Crockenhill<br />
                    Kent<br />
                    BR8 8JS<br />
                </address>
            </div>
        </div>
    </section>
</div>
<p class="text-center copyright"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
