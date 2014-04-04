<div class="row">
    <section class="row">
        <div class="col-md-6">
            <div class="">

                <h4 class="text-center">Latest Morning Sermon</h4>

                <p class="text-center">
                    <a href="sermons/{{$morning->slug}}">{{$morning->title}}</a>
                </p>
                <p class="text-center">{{ $morning->reference }}</p>

            </div>


        </div>
        <div class="col-md-6">

            <h4 class="text-center">Latest Evening Sermon</h4>

            <p class="text-center">
                <a href="sermons/{{$evening->slug}}">{{$evening->title}}</a>
            </p>
            <p class="text-center">{{ $evening->reference }}</p>

        </div>

    </section>

    <section class="col-sm-6">
        <h4 class="text-center">Contact our pastor</h4>
                <p class="text-center"><span class="glyphicon glyphicon-earphone"></span> &nbsp&nbsp 01322 663995</p>
                <p class="text-center"><span class="glyphicon glyphicon-envelope"></span> &nbsp&nbsp {{HTML::mailto('pastor@crockenhill.org', 'pastor@crockenhill.org')}}</span></p>
    </section>
    <section class="col-sm-6">
        <h4 class="text-center">Address</h4>
        <address class="text-center">
            Crockenhill Baptist Church<br />
            Eynsford Road<br />
            Crockenhill<br />
            Kent<br />
            BR8 8JS<br />
        </address>
    </section>
</div>
<p class="text-center copyright"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
