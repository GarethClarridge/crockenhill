<nav class="navbar navbar-dark bg-pattern p-0" role="navigation">
  <div class="container-fluid">

      <a class="navbar-brand p-0" href="/">
        <img src="/images/White.png" height="36" class="d-inline-block align-top p-1" alt="Crockenhill Baptist Church logo">
      </a>

      <a class="navbar-brand navbar-site-name" href="/">
        Crockenhill Baptist Church
      </a>

      <div class="main-links ms-md-auto">
        <a class="btn btn-primary btn-sm ms-sm-auto" href="/christ" role="button">
          <i class="fa fa-cross d-none d-sm-inline">&nbsp</i>
          <span class="">Christ</span>
        </a>
        <a class="btn btn-primary btn-sm mx-1" href="/church" role="button">
          <i class="fa fa-church d-none d-sm-inline">&nbsp</i>
          <span class="">Church</span>
        </a>
        <a class="btn btn-primary btn-sm me-sm-auto" href="/community" role="button">
          <i class="fa fa-users d-none d-sm-inline">&nbsp</i>
          <span class="">Community</span>
        </a>
      </div>

      <button class="btn ms-4" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasMenu" aria-controls="offcanvasMenu">
        <i class="fa fa-bars text-white"></i>
      </button>


  </div>
</nav>

<div class="offcanvas offcanvas-end bg-pattern" tabindex="-1" id="offcanvasMenu" aria-labelledby="offcanvasMenuLabel" data-bs-backdrop="false">
  <div class="offcanvas-header pt-0 pe-3">
    <button type="button" class="btn ms-auto text-white" data-bs-dismiss="offcanvas" aria-label="Close">
      <i class="fa fa-bars text-white"></i>
    </button>
  </div>
  <div class="offcanvas-body">
    <ul class="list-unstyled main-nav text-white h4 ms-3">

      <li class="">
        <a class="" href="/christ">
          Christ
          <span class="sr-only">(current)</span>
        </a>
      </li>

      <li class="">
        <a class="" href="/church">
          Church
          <span class="sr-only">(current)</span>
        </a>
        <ul class="list-unstyled ms-5 main-nav-level-two">
          <li class="">
            <a class="" href="/church/sermons">
              Sermons
            </a>
          </li>
          <li class="">
            <a class="" href="/church/statement-of-faith">
              Statement of faith
            </a>
          </li>
          <li class="">
            <a class="" href="/church/pastor">
              Our pastor
            </a>
          </li>

          <a class="collapse-control" data-bs-toggle="collapse" href="#collapseChurch" role="button" aria-expanded="false" aria-controls="collapseChurch">
            <i class="fa fa-caret-down"></i> More
          </a>
          <div class="collapse ms-5" id="collapseChurch">
            <li class="">
              <a class="" href="/church/links">
                Links
              </a>
            </li>
            <li class="">
              <a class="" href="/church/history">
                History
              </a>
            </li>
          </div>
        </ul>
      </li>

      <li class="">
        <a class="" href="/community">
          Community
        </a>
        <ul class="list-unstyled ms-5 main-nav-level-two">
          <li class="">
            <a class="" href="/community/sunday-services">
              Sunday services
            </a>
          </li>
          <li class="">
            <a class="" href="/community/bible-study">
              Bible studies
            </a>
          </li>
          <li class="">
            <a class="" href="/church/coffee-cup">
              Coffee Cup
            </a>
          </li>

          <a class="collapse-control" data-bs-toggle="collapse" href="#collapseCommunity" role="button" aria-expanded="false" aria-controls="collapseCommunity">
            <i class="fa fa-caret-down"></i> More
          </a>
          <div class="collapse ms-5" id="collapseCommunity">
            <li class="">
              <a class="" href="/community/baby-talk">
                Baby Talk
              </a>
            </li>
            <li class="">
              <a class="" href="/community/adventurers">
                Adventurers
              </a>
            </li>
            <li class="">
              <a class="" href="/community/1150">
                11:50
              </a>
            </li>
            <li class="">
              <a class="" href="/community/messy-church">
                Messy Church
              </a>
            </li>
          </div>
        </ul>
      </li>

      @if ($user)
      <li class="navbar-item">
        <a class="nav-link" href="/members">
          Members
        </a>
      </li>
      @endif
    </ul>
  </div>
</div>
