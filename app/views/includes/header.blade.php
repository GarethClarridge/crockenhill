<nav class="navbar navbar-inverse navbar-fixed-top" role="navigation">
    <div class="navbar-inner">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
	        <a class="navbar-brand logo" href="/">Crockenhill Baptist Church</a>
        </div>
        <div class="navbar-collapse collapse">

            <ul class="nav navbar-nav navbar-right">

              @foreach ($pages as $page)
                @if (Request::is($page['route']))
                    <li class="active">
                    {{ link_to($page['route'], $page['name']) }}
                    <span class="nav-notch">&nbsp</span>
                    </li>
                    
                @else
                    <li>
                    {{ link_to($page['route'], $page['name']) }}
                    </li>
                @endif

              @endforeach

	            @if (Request::is('Members'))
	                <li class="active members-link">
	            @else
	                <li class="members-link">
	            @endif
	                <a href="/members">Members</a>
	            </li>
	            
            </ul>
        </div>
	</div>
</nav>
