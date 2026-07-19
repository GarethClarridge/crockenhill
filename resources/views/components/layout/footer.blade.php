@php
$linkClasses = 'rounded-sm text-cbc-teal-dark underline decoration-cbc-teal-dark/50 underline-offset-2 hover:text-cbc-teal-deeper focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2';
@endphp

<div class="container mx-auto max-w-6xl px-4 pt-12 pb-5 text-center text-cbc-teal-darkest">
  <div class="grid grid-cols-1 gap-6 md:grid-cols-3">

    <section class="rounded-xl border border-white/80 bg-white/85 p-6 shadow-sm backdrop-blur-sm">
      <h2 class="mb-3 font-display text-2xl text-cbc-teal-dark">Catch up</h2>
      <p class="mb-4 text-sm text-slate-700">Keep up with services and recent teaching.</p>
      <div class="flex flex-col items-center space-y-3">
        <a class="inline-flex items-center justify-center rounded-md bg-[linear-gradient(120deg,var(--color-cbc-teal)_0%,var(--color-cbc-teal-dark)_55%,#0e3a3c_100%)] px-3 py-1.5 text-sm text-white no-underline transition-all hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2" href="{{ config('church.social.youtube') }}" rel="noopener" target="_blank">
          Watch Sunday morning services
        </a>
        <a class="{{ $linkClasses }}" href="/christ/sermons/evening" wire:navigate>
          Listen to evening sermons
        </a>
      </div>
    </section>

    <section class="rounded-xl border border-white/80 bg-white/85 p-6 shadow-sm backdrop-blur-sm">
      <h2 class="mb-3 font-display text-2xl text-cbc-teal-dark">Contact us</h2>
      <ul class="mb-0 list-none space-y-1 text-cbc-teal-dark">
        <li>
          <a class="{{ $linkClasses }} flex items-center justify-center gap-2 py-2" href="tel:{{ config('church.phone') }}">
            <x-heroicon-s-phone class="h-5 w-5 shrink-0" aria-hidden="true" />
            {{ config('church.phone_display') }}
          </a>
        </li>
        <li>
          <a class="{{ $linkClasses }} flex items-center justify-center gap-2 py-2" href="mailto:{{ config('church.email_public') }}">
            <x-heroicon-s-envelope class="h-5 w-5 shrink-0" aria-hidden="true" />
            {{ config('church.email_public') }}
          </a>
        </li>
        <li>
          <a class="{{ $linkClasses }} flex items-center justify-center gap-2 py-2" href="{{ config('church.social.facebook') }}" rel="noopener">
            <x-icon-facebook class="h-5 w-5 shrink-0 text-cbc-teal-dark" aria-hidden="true" />
            Like us on Facebook
          </a>
        </li>
        <li>
          <a class="{{ $linkClasses }} flex items-center justify-center gap-2 py-2" href="{{ config('church.social.youtube') }}" rel="noopener">
            <x-icon-youtube class="h-5 w-5 shrink-0 text-cbc-teal-dark" aria-hidden="true" />
            Subscribe on YouTube
          </a>
        </li>
      </ul>
    </section>

    <section class="rounded-xl border border-white/80 bg-white/85 p-6 shadow-sm backdrop-blur-sm">
      <h2 class="mb-3 font-display text-2xl text-cbc-teal-dark">Visit us</h2>
      <address class="mb-4 not-italic text-slate-700">
        {{ config('church.name') }}<br>
        {{ config('church.address.street') }}<br>
        {{ config('church.address.locality') }}<br>
        {{ config('church.address.region') }}<br>
        {{ config('church.address.postal_code') }}
      </address>
      <x-button link="/church/find-us" variant="feature" size="sm" inline>
        Directions and parking
      </x-button>
    </section>

  </div>
</div>

<div class="mx-auto my-4 flex justify-center p-4 md:w-1/2" role="complementary" aria-label="Church affiliation">
  <a class="fiec-footer inline-flex rounded-sm focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-cbc-teal" href="https://www.fiec.org.uk" rel="noopener">
    <img src="/images/affiliated-to-FIEC-logo-white.webp" alt="Affiliated to the Fellowship of Independent Evangelical Churches" loading="lazy" width="384" height="68">
  </a>
</div>

<div class="bg-white/85 px-6 py-4 text-cbc-teal-darkest backdrop-blur-sm">
  <div class="mx-auto max-w-6xl px-4 text-center text-sm">
    <p>&copy; {{ date('Y') }} {{ config('church.name') }}</p>
    <p>
      {{ config('church.name') }} is a <a class="{{ $linkClasses }}" href="https://www.gov.uk/setting-up-charity/structures" rel="noopener">charitable incorporated organisation</a>,
      charity number <a class="{{ $linkClasses }}" href="https://register-of-charities.charitycommission.gov.uk/charity-search/-/charity-details/{{ config('church.charity_number') }}" rel="noopener">{{ config('church.charity_number') }}</a>.
    </p>
  </div>
</div>