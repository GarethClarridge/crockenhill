<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">

  <title>@yield('title') - Crockenhill Baptist Church</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />

  @section('description') {{isset($description) ? $description : '<meta name="description" content="Crockenhill Baptist Church">'}} @endsection

  <link type="text/css" rel="stylesheet" href="/stylesheets/main.css?v=5.2.0">
  <link type="text/css" rel="stylesheet" href="/stylesheets/print.css" media="print">
  @stack('styles')

  <link href='http://fonts.googleapis.com/css?family=Lato:400,300italic|Oswald' rel='stylesheet' type='text/css'>
  <link href="//maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css" rel="stylesheet">

  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=GvJNbAA7Wv">
  <link rel="icon" type="image/png" href="/favicon-32x32.png?v=GvJNbAA7Wv" sizes="32x32">
  <link rel="icon" type="image/png" href="/favicon-16x16.png?v=GvJNbAA7Wv" sizes="16x16">
  <link rel="manifest" href="/manifest.json?v=GvJNbAA7Wv">
  <link rel="mask-icon" href="/safari-pinned-tab.svg?v=GvJNbAA7Wv" color="#16324f">
  <link rel="shortcut icon" href="/favicon.ico?v=GvJNbAA7Wv">
  <meta name="theme-color" content="#16324f">

  <script>
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

    ga('create', 'UA-81335303-1', 'auto');
    ga('send', 'pageview');

  </script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
  <script src="/scripts/bootstrap.min.js"></script>
  <script src="/scripts/all.js"></script>
  @stack('scripts')
</head>

<body>
        <!--[if lt IE 7]>
            <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
        <![endif]-->

    <header>
        @include('includes.header')
    </header>

    @yield('content')

    <footer class="navbar navbar-fixed-bottom bg-primary">
        @include('includes.footer')
    </footer>
</body>
</html>
