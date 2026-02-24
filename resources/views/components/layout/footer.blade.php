<div class="container mx-auto max-w-6xl px-4 text-center text-cbc-teal-darkest">
  <div class="my-5 grid grid-cols-1 gap-6 md:grid-cols-3">

    <section class="rounded-xl border border-white/80 bg-white/85 p-6 text-cbc-teal-darkest shadow-sm backdrop-blur-sm">
      <h3 class="mb-3 font-display text-2xl text-cbc-teal">Catch up</h3>
      <p class="mb-4 text-sm text-slate-700">Keep up with services and recent teaching.</p>
      <div class="space-y-3">
        <a class="inline-flex items-center text-cbc-teal-dark underline decoration-cbc-teal-dark/50 underline-offset-2 hover:text-cbc-teal-deeper" href="{{ config('organization.social.youtube') }}">
          Watch Sunday morning services
        </a>
        <br>
        <a class="inline-flex items-center text-cbc-teal-dark underline decoration-cbc-teal-dark/50 underline-offset-2 hover:text-cbc-teal-deeper" href="/christ/sermons" wire:navigate>
          Listen to evening sermons
        </a>
      </div>
    </section>

    <section class="rounded-xl border border-white/80 bg-white/85 p-6 text-cbc-teal-dark shadow-sm backdrop-blur-sm">
      <h3 class="mb-3 font-display text-2xl text-cbc-teal-dark">Contact us</h3>
      <ul class="mx-auto mb-0 max-w-xs list-none space-y-2 text-left text-cbc-teal-dark">
        <li class="my-2">
          <div class="flex items-center">
            <x-heroicon-s-phone class="mr-2 h-5 w-5" />
            <a class="underline decoration-current/50 underline-offset-2 hover:text-cbc-teal-dark" href="tel:{{ config('organization.phone') }}">{{ config('organization.phone_display') }}</a>
          </div>
        </li>
        <li class="my-2">
          <div class="flex items-center">
            <x-heroicon-s-envelope class="mr-2 h-5 w-5" />
            <a class="underline decoration-current/50 underline-offset-2 hover:text-cbc-teal-dark" href="mailto:{{ config('organization.email_public') }}">{{ config('organization.email_public') }}</a>
          </div>
        </li>
        <li class="my-2">
          <div class="flex items-center">
            <x-icon-facebook class="mr-1 h-5 w-5 text-cbc-teal-dark" />
            <a class="underline decoration-current/50 underline-offset-2 hover:text-cbc-teal-dark" href="{{ config('organization.social.facebook') }}">Like us on Facebook</a>
          </div>
        </li>
        <li class="my-2">
          <div class="flex items-center">
            <x-icon-youtube class="mr-1 h-5 w-5 text-cbc-teal-dark" />
            <a class="underline decoration-current/50 underline-offset-2 hover:text-cbc-teal-dark" href="{{ config('organization.social.youtube') }}">Subscribe on YouTube</a>
          </div>
        </li>
      </ul>
    </section>

    <section class="rounded-xl border border-white/80 bg-white/85 p-6 text-cbc-teal-darkest shadow-sm backdrop-blur-sm">
      <h3 class="mb-3 font-display text-2xl text-cbc-teal">Visit us</h3>
      <address class="mb-4 not-italic text-slate-700">
        {{ config('organization.name') }}, {{ config('organization.address.street') }}, {{ config('organization.address.locality') }}, {{ config('organization.address.region') }}, {{ config('organization.address.postal_code') }}
      </address>
      <a class="inline-flex items-center text-cbc-teal-dark underline decoration-cbc-teal-dark/50 underline-offset-2 hover:text-cbc-teal-deeper" href="/church/find-us" wire:navigate>
        Directions and parking
      </a>
    </section>

  </div>
</div>

<section class="mx-auto my-4 flex justify-center p-4 md:w-1/2">
  <a class="fiec-footer inline-flex" href="https://www.fiec.org.uk">
    <img src="/images/affiliated-to-FIEC-logo-white.webp" alt="Affiliated to the Fellowship of Independent Evangelical Churches" loading="lazy" width="384" height="68">
  </a>
</section>

<section class="-mb-6 -mx-6 mt-6 bg-white/85 px-6 py-4 text-cbc-teal-darkest backdrop-blur-sm">
  <div class="mx-auto max-w-6xl px-4 text-sm">
    <p class="text-center">
      <small>
        &copy; {{ date('Y') }} {{ config('organization.name') }}
      </small>
    </p>
    <p class="text-center">
      <small>
        {{ config('organization.name') }} is a <a class="underline decoration-cbc-teal-dark/50 underline-offset-2 hover:text-cbc-teal-deeper" href="https://www.gov.uk/setting-up-charity/structures">charitable incorporated organisation</a>,
        charity number <a class="underline decoration-cbc-teal-dark/50 underline-offset-2 hover:text-cbc-teal-deeper" href="https://register-of-charities.charitycommission.gov.uk/charity-search/-/charity-details/{{ config('organization.charity_number') }}">{{ config('organization.charity_number') }}</a>.
      </small>
    </p>
  </div>
</section>
