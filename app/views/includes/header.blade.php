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
            <ul class="nav navbar-nav">

                @foreach ($pages as $page)
		            @if (Request::is($page['route']))
		                <li class="active">
		            @else
		                <li>
		            @endif
		                {{ link_to($page['route'], $page['name']) }}
		            </li>
	            @endforeach

            </ul>
            <ul class="nav navbar-nav pull-right">
	            @if (Request::is('Members'))
	                <li class="active">
	            @else
	                <li>
	            @endif
	                <a href="/members">Members</a>
	            </li>
	            
            </ul>
        </div>
	</div>
</nav>
