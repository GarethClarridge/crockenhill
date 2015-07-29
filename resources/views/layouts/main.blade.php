<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <title>@yield('title') - Crockenhill Baptist Church</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />

    @yield('description')

    {!! \HTML::style('stylesheets/main.css') !!}
    <link href='http://fonts.googleapis.com/css?family=Lato:400,300italic|Oswald' rel='stylesheet' type='text/css'>
    <link href="//maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css" rel="stylesheet">
    
    <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="js/html5shiv.js"></script>
      <script src="js/respond.min.js"></script>
    <![endif]-->

</head>

<body>
        <!--[if lt IE 7]>
            <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
        <![endif]-->

    <header>
        @include('includes.header')
    </header>

    @yield('content')
    
    <footer>
        @include('includes.footer')
    </footer>
    
    <!-- Include all compiled plugins -->
    
    <script src="//code.jquery.com/jquery-1.11.0.min.js"></script>
    <script src="/scripts/bootstrap.min.js"></script>
    <script src="/scripts/all.js"></script>

</body>
</html>
