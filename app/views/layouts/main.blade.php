<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <title>@yield('title') - Crockenhill Baptist Church</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="msvalidate.01" content="2EF7ECDA9644EAD5B1B36A960808B8DB" />

    @yield('description')

    {{ HTML::style('stylesheets/main.css') }}
    <link href='http://fonts.googleapis.com/css?family=Lato:400,300italic|Oswald' rel='stylesheet' type='text/css'>
    <link href="//maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css" rel="stylesheet">
    
    <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="js/html5shiv.js"></script>
      <script src="js/respond.min.js"></script>
    <![endif]-->

    <!-- Begin Cookie Consent plugin by Silktide - http://silktide.com/cookieconsent -->
      <link rel="stylesheet" type="text/css" href="http://assets.cookieconsent.silktide.com/current/style.min.css"/>
      <script type="text/javascript" src="http://assets.cookieconsent.silktide.com/current/plugin.min.js"></script>
      <script type="text/javascript">
      // <![CDATA[
      cc.initialise({
        cookies: {
          analytics: {},
          necessary: {}
        },
        settings: {
          consenttype: "implicit",
          style: "monochrome",
          onlyshowbanneronce: true,
          bannerPosition: "bottom"
        },
        strings: {
          analyticsDefaultDescription: 'Like nearly all sites, we anonymously measure use of our website to help us improve it for users.'
        }
      });
      // ]]>
      </script>
      <!-- End Cookie Consent plugin -->

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
    
    <script type="text/plain" class="cc-onconsent-necessary" src="//code.jquery.com/jquery-1.11.0.min.js"></script>
    <script type="text/plain" class="cc-onconsent-necessary" src="../scripts/all.js"></script>
    <script type="text/plain" class="cc-onconsent-analytics">
      (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
      (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
      m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
      })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

      ga('create', 'UA-59031222-1', 'auto');
      ga('send', 'pageview');

    </script>
</body>
</html>
