<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">

  <title>@yield('title') - Crockenhill Baptist Church</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />

  @section('description') {{isset($description) ? $description : '<meta name="description" content="Crockenhill Baptist Church">'}} @endsection

  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=GvJNbAA7Wv">
  <link rel="icon" type="image/png" href="/favicon-32x32.png?v=GvJNbAA7Wv" sizes="32x32">
  <link rel="icon" type="image/png" href="/favicon-16x16.png?v=GvJNbAA7Wv" sizes="16x16">
  <link rel="manifest" href="/manifest.json?v=GvJNbAA7Wv">
  <link rel="mask-icon" href="/safari-pinned-tab.svg?v=GvJNbAA7Wv" color="#16324f">
  <link rel="shortcut icon" href="/favicon.ico?v=GvJNbAA7Wv">
  <meta name="theme-color" content="#16324f">

  <script>
    (function(i, s, o, g, r, a, m) {
      i['GoogleAnalyticsObject'] = r;
      i[r] = i[r] || function() {
        (i[r].q = i[r].q || []).push(arguments)
      }, i[r].l = 1 * new Date();
      a = s.createElement(o),
        m = s.getElementsByTagName(o)[0];
      a.async = 1;
      a.src = g;
      m.parentNode.insertBefore(a, m)
    })(window, document, 'script', 'https://www.google-analytics.com/analytics.js', 'ga');

    ga('create', 'UA-81335303-1', 'auto');
    ga('send', 'pageview');
  </script>

  @vite(['resources/css/main.scss', 'resources/js/app.js'])

</head>

<body class="bg-slate-200">
  <header x-data="{ expanded: false }">
    @include('includes.header')
  </header>

  @yield('content')

  <footer class="bg-cbc-pattern bg-cover p-6 mt-6">
    @include('includes.footer')
  </footer>
</body>

</html>