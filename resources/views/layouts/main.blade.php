<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">

  <title>@yield('title') - Crockenhill Baptist Church</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />

  @section('description') {{isset($description) ? $description : '<meta name="description" content="Crockenhill Baptist Church">'}} @endsection

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;1,300;1,400;1,700&family=Oswald:wght@200;300;400;500;600;700&display=swap" rel="stylesheet">

  <script src="https://kit.fontawesome.com/cbe28a5c6a.js" crossorigin="anonymous"></script>

  <!-- <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.5.0/css/all.css" integrity="sha384-B4dIYHKNBt8Bc12p+WXckhzcICo0wtJAoU8YZTY5qE0Id1GSseTk6S+L3BlXeVIU" crossorigin="anonymous"> -->

  <link type="text/css" rel="stylesheet" href="/stylesheets/main.css?v=7.2.1">
  <link type="text/css" rel="stylesheet" href="/stylesheets/print.css" media="print">
  @stack('styles')

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
  <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
  <script src="/scripts/all.js?v=7.0.1"></script>
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

    <footer class="bg-pattern p-4">
        @include('includes.footer')
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
</body>
</html>
