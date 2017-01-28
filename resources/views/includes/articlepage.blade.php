	<main class="container">
	    <div class="row">
            <div class="col-md-9">
                <article class="card">
                    <div class="header-container">
                        <h1>{{$header}}</h1>
                    </div>
                    <ol class="breadcrumb">
                        <li><a href="/">Home</a></li>
                        {{$breadcrumbs}}
                    </ol>
                    @yield('main-content')
                </article>
            </div>
        </div>
    @yield('aside')

	</main>
