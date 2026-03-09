{{-- Alpine.js is automatically loaded by Livewire 3 --}}

@php
  $isChristSection = request()->is('christ') || request()->is('christ/*');
  $isChurchSection = request()->is('church') || request()->is('church/*');
  $isCommunitySection = request()->is('community') || request()->is('community/*');
@endphp

<div class="relative">
  <div class="w-100 grid grid-cols-7 justify-between bg-cbc-pattern bg-cover text-white lg:grid-cols-12">

  <a class="p-2" href="/" wire:navigate>
    <img src="/svg/IconWhite.svg" class="inline-block max-h-8 align-top" alt="Crockenhill Baptist Church logo" width="30" height="32">
  </a>

  <a
    class="col-span-5 flex text-center font-display text-xl min-[400px]:text-2xl lg:col-start-2"
    :class="expanded ? 'lg:pointer-events-none lg:opacity-0' : 'lg:pointer-events-auto lg:opacity-100'"
    href="/"
    wire:navigate
  >
    <span class="mx-auto my-auto inline-block align-middle pb-1">
      Crockenhill Baptist Church
    </span>
  </a>

  <a
    class="absolute inset-y-0 left-16 right-16 z-10 hidden items-center justify-center text-center font-display text-xl opacity-0 transition-opacity duration-200 min-[400px]:text-2xl lg:flex"
    :class="expanded ? 'pointer-events-auto opacity-100' : 'pointer-events-none opacity-0'"
    :aria-hidden="!expanded"
    :inert="!expanded"
    href="/"
    wire:navigate
  >
    <span class="block truncate px-6 pb-1">
      Crockenhill Baptist Church
    </span>
  </a>

  <div
    class="col-span-5 hidden h-full w-100 self-stretch transition-opacity duration-150 lg:block"
    :class="expanded ? 'pointer-events-none opacity-0' : 'pointer-events-auto opacity-100'"
    :aria-hidden="expanded"
    :inert="expanded"
  >
    <ul class="mx-auto flex h-full items-stretch fill-white font-display text-l">
      <li class="flex">
        <a class="flex h-full items-center justify-center gap-2 border-b-4 px-8 fill-current transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white {{ $isChristSection ? 'border-white text-white font-semibold' : 'border-transparent text-white/90 hover:border-white/40 hover:text-white' }}"
           href="/christ" wire:navigate @if($isChristSection) aria-current="page" @endif>
          <x-icon-cross class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Christ</span>
        </a>
      </li>

      <li class="flex">
        <a class="flex h-full items-center justify-center gap-2 border-b-4 px-8 fill-current transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white {{ $isChurchSection ? 'border-white text-white font-semibold' : 'border-transparent text-white/90 hover:border-white/40 hover:text-white' }}"
           href="/church" wire:navigate @if($isChurchSection) aria-current="page" @endif>
          <x-icon-church class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Church</span>
        </a>
      </li>

      <li class="flex">
        <a class="flex h-full items-center justify-center gap-2 border-b-4 px-8 fill-current transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white {{ $isCommunitySection ? 'border-white text-white font-semibold' : 'border-transparent text-white/90 hover:border-white/40 hover:text-white' }}"
           href="/community" wire:navigate @if($isCommunitySection) aria-current="page" @endif>
          <x-heroicon-s-user-group class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Community</span>
        </a>
      </li>
    </ul>
  </div>

  <button
    class="ms-4 flex items-center justify-end rounded px-3 py-1 text-right align-right font-normal leading-normal no-underline select-none whitespace-no-wrap transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark lg:col-start-12"
    type="button"
    role="button"
    aria-label="Navigation"
    @click="expanded = ! expanded"
    @keydown.window.escape="expanded = false"
    :aria-expanded="expanded"
    aria-controls="mobile-menu"
  >
    <x-heroicon-m-bars-3 x-show="!expanded" class="h-6 w-6" />
    <x-heroicon-m-x-mark x-show="expanded" class="h-6 w-6" x-cloak />
  </button>

  </div>

  <div
    x-show="expanded"
    x-transition:enter="transform-gpu transition ease-out duration-200"
    x-transition:enter-start="-translate-y-3 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transform-gpu transition ease-in duration-150"
    x-transition:leave-start="translate-y-0 opacity-100"
    x-transition:leave-end="-translate-y-2 opacity-0"
    id="mobile-menu"
    class="absolute left-0 right-0 top-full z-30 -mt-px w-screen bg-gradient-to-bl from-cbc-teal-deeper/95 via-cbc-teal-dark/95 to-cbc-teal/92 p-6 font-sans normal-case text-base leading-relaxed text-white shadow-2xl ring-1 ring-black/10 backdrop-blur-md"
    tabindex="-1"
    x-cloak
  >
    <ul class="mt-3 grid grid-cols-1 gap-10 text-center md:grid-cols-3 md:gap-8">

    <li>
      <div class="flex justify-center border-b border-white/15 pb-4">
        <a class="inline-flex items-center justify-center gap-2 font-display text-lg font-normal text-white no-underline transition-colors duration-150 hover:text-white/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/christ" wire:navigate>
          <x-icon-cross class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Christ</span>
        </a>
      </div>
      <ul class="mt-5 space-y-4">
        @foreach ($pages as $page)
        @if ($page->area->value == 'christ')
        <li class="leading-none">
          <a class="inline-flex rounded-sm px-2 py-1 text-base font-medium text-white/85 no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/christ/{{$page->slug}}" wire:navigate>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
        @auth
        <li class="leading-none">
          <a class="inline-flex rounded-sm px-2 py-1 text-base font-medium text-white/85 no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="{{ route('childrens-corner.index') }}" wire:navigate>
            Children's Corner
          </a>
        </li>
        @endauth
      </ul>
    </li>

    <li>
      <div class="flex justify-center border-b border-white/15 pb-4">
        <a class="inline-flex items-center justify-center gap-2 font-display text-lg font-normal text-white no-underline transition-colors duration-150 hover:text-white/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/church" wire:navigate>
          <x-icon-church class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Church</span>
        </a>
      </div>
      <ul class="mt-5 space-y-4">
        @foreach ($pages as $page)
        @if ($page->area->value == 'church')
        <li class="leading-none">
          <a class="inline-flex rounded-sm px-2 py-1 text-base font-medium text-white/85 no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/church/{{$page->slug}}" wire:navigate>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
        @auth
        <li class="leading-none">
          <a class="inline-flex rounded-sm px-2 py-1 text-base font-medium text-white/85 no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="{{ route('church.songs.index') }}" wire:navigate>
            Songs
          </a>
        </li>
        @endauth
      </ul>
    </li>

    <li>
      <div class="flex justify-center border-b border-white/15 pb-4">
        <a class="inline-flex items-center justify-center gap-2 font-display text-lg font-normal text-white no-underline transition-colors duration-150 hover:text-white/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/community" wire:navigate>
          <x-heroicon-s-user-group class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Community</span>
        </a>
      </div>
      <ul class="mt-5 space-y-4">
        @foreach ($pages as $page)
        @if ($page->area->value == 'community')
        <li class="leading-none">
          <a class="inline-flex rounded-sm px-2 py-1 text-base font-medium text-white/85 no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/community/{{$page->slug}}" wire:navigate>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
      </ul>
    </li>

    @if ($user)
    <li class="md:col-span-3">
      <div class="flex justify-center border-t border-white/10 pt-2">
        <a class="inline-flex rounded-sm px-2 py-1 text-base font-medium text-white/85 no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" href="/church/members" wire:navigate>
          Members
        </a>
      </div>
    </li>
    @endif
    </ul>
  </div>
</div>
