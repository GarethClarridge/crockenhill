<div class="text-center container mx-auto sm:px-4 max-w-full mx-auto sm:px-4">
  <div class="flex flex-wrap  my-5 row-cols-1 row-cols-md-3 g-1">

    <x-card heading="Catch up">
      <div class="p-2 mb-3">
        <x-button link="/https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA">
          See morning services on our YouTube channel.
        </x-button>
      </div>
      
      <div class="p2">
        <x-button link="/christ/sermons">
          Listen to evening sermons on our website.
        </x-button>
      </div>
      
    </x-card>

    <x-card heading="Contact us">
      <ul class="">
        <li class="relative block py-3 px-6 no-underline">
          <i class="fa fa-phone"></i>
          <a class="ml-1" href="tel:01322 663995">01322 663995</a>
        </li>
        <li class="relative block py-3 px-6 no-underline">
          <i class="fa fa-envelope"></i>
          <a class="ml-1" href="mailto:pastor@crockenhill.org">pastor@crockenhill.org</a>
        </li>
        <li class="relative block py-3 px-6 no-underline">
          <i class="fa-brands fa-facebook-square"></i>
          <a class="ml-1" href="https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905">Like us on Facebook</a>
        </li>
        <li class="relative block py-3 px-6 no-underline">
          <i class="fa-brands fa-youtube"></i>
          <a class="ml-1" href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA">Subscribe to our YouTube channel</a>
        </li>
      </ul>
      
    </x-card>

    <x-card heading="Visit us">
      <address class="mb-0">
        Crockenhill Baptist Church, Eynsford Road, Crockenhill, Kent, BR8 8JS
      </address>

      <iframe 
        loading="lazy" 
        frameborder="0" 
        src="https://maps.google.co.uk/maps?f=q&source=embed&hl=en&geocode=&q=crockenhill+baptist+church&sll=51.386500,0.162500&sspn=0.035000,0.055000&t=m&gl=uk&ie=UTF8&hq=crockenhill+baptist+church&hnear=&filter=0&update=1&ll=51.389352,0.145226&spn=0.051418,0.109863&z=13&output=embed"
        class="my-6 aspect-square"
        width="100%"
        >
      </iframe>
      
      <div class="p2">
        <x-button link="/church/find-us">
          Directions and parking
        </x-button>
      </div>
      
    </x-card>

  </div>
</div>


<section class="md:w-1/2 mx-auto p-4 my-4">
  <img src="/svg/OutlineNameWhite.svg" width="100%" alt="Crockenhill Baptist Church logo">
</section>

<section class="md:w-1/2 mx-auto p-4 my-4">
    <a class="fiec-footer" href="https://www.fiec.org.uk">
      <img src="/images/fiec-affiliated-tagline-white.png" width="100%" alt="Affiliated to the Fellowship of Independent Evangelical Churches">
    </a>
</section>

<section class="pr-4 text-white">
    <p class="text-center"><small>&copy; {{ date('Y') }} Crockenhill Baptist Church</small></p>
</section>
