<nav class="navbar navbar-expand-lg navbar-dark bg-primary p-0" role="navigation">
  <a class="navbar-brand p-0" href="/">
    <img src="/images/IconBackground.png" height="40" class="d-inline-block align-top p-0" alt="Crockenhill Baptist Church logo">
  </a>

  <a class="navbar-brand abs" href="/">Crockenhill Baptist Church</a>

  <button type="button" class="navbar-toggler" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-label="Toggle navigation">
    MENU
  </button>

  <div class="collapse navbar-collapse w-100" id="navbarSupportedContent">
    <ul class="navbar-nav ml-auto site-sections text-center text-lg-left">

      @foreach ($pages as $page)
        @if (\Request::is($page['route'].'/*'))
          <li class="navbar-item active">
            <a class="nav-link" href="/{{$page['route']}}">{{$page['name']}}<span class="sr-only">(current)</span></a>
          </li>
        @elseif  (\Request::is($page['route']))
          <li class="navbar-item active">
            <a class="nav-link" href="/{{$page['route']}}">{{$page['name']}}<span class="sr-only">(current)</span></a>
          </li>
        @else
          <li class="navbar-item">
            <a class="nav-link" href="/{{$page['route']}}">{{$page['name']}}</a>
          </li>
        @endif
      @endforeach

      @if (isset ($user))
        <span class="navbar-text user-name">
          <span class="navbar-seperator">&nbsp | &nbsp &nbsp</span>
          <i class="far fa-user">&nbsp</i>
          {{ $user->name }}
        </span>
      @endif
    </ul>
  </div>
</nav>
