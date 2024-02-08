<div class="text-center container mx-auto sm:px-4 max-w-full mx-auto sm:px-4">
  <div class="flex flex-wrap  my-5 row-cols-1 row-cols-md-3 g-1">

    <x-card heading="Catch up">
      <div class="p-2 mb-3">
        <x-button link="https://www.youtube.com/@crockenhillbaptistchurch9727/streams">
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
      <ul class="mx-6 px-6 mb-6 pb-6 list-none justify-start prose">
        <li class="my-2">
          <div class="flex items-center">
            <x-heroicon-s-phone class="h-5 w-5 mr-2" />
            <a class="" href="tel:01322 663995">01322 663995</a>
          </div>
        </li>
        <li class="my-2">
          <div class="flex items-center">
            <x-heroicon-s-envelope class="h-5 w-5 mr-2" />
            <a class="" href="mailto:pastor@crockenhill.org">pastor@crockenhill.org</a>
          </div>
        </li>
        <li class="my-2">
          <div class="flex items-center">
            <x-icon-facebook class="h-5 w-5 mr-1" />
            <a class="" href="https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905">Like us on Facebook</a>
          </div>
        </li>
        <li class="my-2">
          <div class="flex items-center">
            <x-icon-youtube class="h-5 w-5 mr-1" />
            <a class="" href="https://www.youtube.com/@crockenhillbaptistchurch9727/streams">Subscribe to our YouTube channel</a>
          </div>
        </li>
      </ul>

    </x-card>

    <x-card heading="Visit us">
      <address class="mb-6">
        Crockenhill Baptist Church, Eynsford Road, Crockenhill, Kent, BR8 8JS
      </address>

      <!-- <iframe 
        loading="lazy" 
        frameborder="0" 
        src="https://maps.google.co.uk/maps?f=q&source=embed&hl=en&geocode=&q=crockenhill+baptist+church&sll=51.386500,0.162500&sspn=0.035000,0.055000&t=m&gl=uk&ie=UTF8&hq=crockenhill+baptist+church&hnear=&filter=0&update=1&ll=51.389352,0.145226&spn=0.051418,0.109863&z=13&output=embed"
        class="my-6 aspect-square"
        width="100%"
        >
      </iframe> -->

      <div class="p2">
        <x-button link="/church/find-us">
          Directions and parking
        </x-button>
      </div>

    </x-card>

  </div>
</div>

<section class="md:w-1/2 mx-auto p-4 my-4">
  <a class="fiec-footer" href="https://www.fiec.org.uk">
    <img src="/images/affiliated-to-FIEC-logo-white.webp" alt="Affiliated to the Fellowship of Independent Evangelical Churches" loading="lazy">
  </a>
</section>

<section class="pr-4 text-white">
  <p class="text-center">
    <small>
      &copy; {{ date('Y') }} Crockenhill Baptist Church
    </small>
  </p>
  <p class="text-center">
    <small>
      Crockenhill Baptist Church is a <a class="underline" href="https://www.gov.uk/setting-up-charity/structures">charitable incorporated organisation</a>,
      charity number <a class="underline" href="https://register-of-charities.charitycommission.gov.uk/charity-search/-/charity-details/5203647">1199873</a>.
    </small>
  </p>
</section>