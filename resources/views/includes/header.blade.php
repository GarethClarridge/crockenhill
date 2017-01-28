<nav class="navbar navbar-inverse navbar-fixed-top" role="navigation">
  <div class="navbar-inner">
    <div class="navbar-header">
      <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
        <span class="sr-only">Toggle navigation</span>
        MENU
      </button>
      <a class="navbar-left org-name" href="/">
        <img src="/images/IconBackground.png" alt="Crockenhill Baptist Church logo">
        <span>Crockenhill Baptist Church</span>
      </a>
    </div>
    <div class="navbar-collapse collapse">

      <ul class="nav navbar-nav navbar-right">

        @foreach ($pages as $page)
          @if (\Request::is($page['route'].'/*'))
            <li class="active">
              <a href="{{$page['route']}}">{{$page['name']}}</a>
            </li>
          @elseif  (\Request::is($page['route']))
            <li class="active">
              <a href="{{$page['route']}}">{{$page['name']}}</a>
            </li>
          @else
            <li>
              <a href="{{$page['route']}}">{{$page['name']}}</a>
            </li>
          @endif

        @endforeach

        <!-- <li>
          <a href="http://www.facebook.com/crockenhill">
            <i class="fa fa-facebook"></i>
          </a>
        </li>

        <li>
          <a href="http://www.twitter.com/crockenhill">
            <i class="fa fa-twitter"></i>
          </a>
        </li>

        <li>
          <a href="http://www.facebook.com/crockenhill">
            <i class="fa fa-google-plus"></i>
          </a>
        </li> -->

      </ul>
    </div>
	</div>
</nav>
