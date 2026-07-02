{{-- Alpine.js is automatically loaded by Livewire 3 --}}

@php
  $isChristSection = request()->is('christ') || request()->is('christ/*');
  $isChurchSection = request()->is('church') || request()->is('church/*');
  $isCommunitySection = request()->is('community') || request()->is('community/*');
@endphp

<div class="relative">
  <div class="w-full grid grid-cols-7 justify-between bg-cbc-pattern bg-size-cover text-white lg:grid-cols-12">

  <a
    class="col-span-6 flex items-center gap-2 p-2 rounded transition-all active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark lg:col-span-6"
    x-bind:class="expanded ? 'lg:pointer-events-none lg:opacity-0' : 'lg:pointer-events-auto lg:opacity-100'"
    x-bind:aria-hidden="expanded"
    x-bind:inert="expanded"
    href="/"
    wire:navigate
  >
    <img src="/svg/IconWhite.svg" class="inline-block max-h-8 align-top" alt="" width="30" height="32">
    <span class="font-display text-xl min-[400px]:text-2xl pb-1 lg:text-2xl">
      Crockenhill Baptist Church
    </span>
  </a>

  <a
    class="absolute inset-y-0 left-16 right-16 z-10 hidden items-center justify-center text-center font-display text-xl opacity-0 transition-all duration-200 active:scale-95 min-[400px]:text-2xl lg:flex rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark"
    x-bind:class="expanded ? 'pointer-events-auto opacity-100' : 'pointer-events-none opacity-0'"
    x-bind:aria-hidden="!expanded"
    x-bind:inert="!expanded"
    href="/"
    wire:navigate
  >
    <span class="block truncate px-6 pb-1">
      Crockenhill Baptist Church
    </span>
  </a>

  <nav
    class="col-span-5 hidden h-full w-full self-stretch transition-opacity duration-150 lg:block"
    x-bind:class="expanded ? 'pointer-events-none opacity-0' : 'pointer-events-auto opacity-100'"
    x-bind:aria-hidden="expanded"
    x-bind:inert="expanded"
    aria-label="Main navigation"
  >
    <ul class="mx-auto flex h-full items-stretch fill-white font-display text-l">
      <li class="flex">
        <a class="flex h-full items-center justify-center gap-2 border-b-4 px-8 fill-current transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isChristSection ? 'border-white text-white font-semibold' : 'border-transparent text-white/90 hover:border-white/40 hover:text-white' }}"
           href="/christ" wire:navigate @if($isChristSection) aria-current="page" @endif>
          <x-icon-cross class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Christ</span>
        </a>
      </li>

      <li class="flex">
        <a class="flex h-full items-center justify-center gap-2 border-b-4 px-8 fill-current transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isChurchSection ? 'border-white text-white font-semibold' : 'border-transparent text-white/90 hover:border-white/40 hover:text-white' }}"
           href="/church" wire:navigate @if($isChurchSection) aria-current="page" @endif>
          <x-icon-church class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Church</span>
        </a>
      </li>

      <li class="flex">
        <a class="flex h-full items-center justify-center gap-2 border-b-4 px-8 fill-current transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isCommunitySection ? 'border-white text-white font-semibold' : 'border-transparent text-white/90 hover:border-white/40 hover:text-white' }}"
           href="/community" wire:navigate @if($isCommunitySection) aria-current="page" @endif>
          <x-heroicon-s-user-group class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Community</span>
        </a>
      </li>
    </ul>
  </nav>

  <button
    class="ms-4 flex items-center justify-end rounded px-3 py-1 select-none whitespace-nowrap transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark lg:col-start-12"
    type="button"
    aria-label="Open navigation"
    x-bind:aria-label="expanded ? 'Close navigation' : 'Open navigation'"
    @click="expanded = ! expanded"
    @keydown.window.escape="expanded = false"
    x-bind:aria-expanded="expanded"
    aria-controls="mobile-menu"
  >
    <x-heroicon-m-bars-3 x-show="!expanded" class="h-6 w-6" aria-hidden="true" />
    <x-heroicon-m-x-mark x-show="expanded" class="h-6 w-6" aria-hidden="true" x-cloak />
  </button>

  </div>

  <nav
    x-show="expanded"
    x-transition:enter="transform-gpu transition ease-out duration-200"
    x-transition:enter-start="-translate-y-3 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transform-gpu transition ease-in duration-150"
    x-transition:leave-start="translate-y-0 opacity-100"
    x-transition:leave-end="-translate-y-2 opacity-0"
    x-trap.noscroll="expanded"
    id="mobile-menu"
    class="absolute left-0 right-0 top-full z-30 -mt-px w-screen bg-gradient-to-bl from-cbc-teal-deeper/95 via-cbc-teal-dark/95 to-cbc-teal/92 p-6 font-sans normal-case text-base leading-relaxed text-white shadow-2xl ring-1 ring-black/10 backdrop-blur-md"
    tabindex="-1"
    aria-label="Mobile navigation"
    x-cloak
  >
    <ul class="mt-3 grid grid-cols-1 gap-10 text-center md:grid-cols-3 md:gap-8">

    <li>
      <div class="flex justify-center border-b border-white/15 pb-4">
        <a class="inline-flex items-center justify-center gap-2 font-display text-lg font-normal no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isChristSection ? 'text-white font-semibold' : 'text-white/80' }}"
           href="/christ" wire:navigate @if($isChristSection) aria-current="page" @endif>
          <x-icon-cross class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Christ</span>
        </a>
      </div>
      <ul class="mt-5 space-y-4">
        @foreach ($pages as $page)
        @if ($page->area->value == 'christ')
        @php $isActive = request()->is('christ/'.$page->slug); @endphp
        <li class="leading-none">
          <a class="inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isActive ? 'bg-white/20 text-white font-bold shadow-sm' : 'text-white/85 font-medium hover:text-white hover:bg-white/5' }}"
             href="/christ/{{$page->slug}}" wire:navigate @if($isActive) aria-current="page" @endif>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
        @php $isActive = request()->is('christ/childrens-corner*'); @endphp
        @if($canAccessChildrensCorner)
        <li class="leading-none">
          <a class="inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isActive ? 'bg-white/20 text-white font-bold shadow-sm' : 'text-white/85 font-medium hover:text-white hover:bg-white/5' }}"
             href="{{ route('childrens-corner.index') }}" wire:navigate @if($isActive) aria-current="page" @endif>
            Children's Corner
          </a>
        </li>
        @endif
      </ul>
    </li>

    <li>
      <div class="flex justify-center border-b border-white/15 pb-4">
        <a class="inline-flex items-center justify-center gap-2 font-display text-lg font-normal no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isChurchSection ? 'text-white font-semibold' : 'text-white/80' }}"
           href="/church" wire:navigate @if($isChurchSection) aria-current="page" @endif>
          <x-icon-church class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Church</span>
        </a>
      </div>
      <ul class="mt-5 space-y-4">
        @foreach ($pages as $page)
        @if ($page->area->value == 'church')
        @php $isActive = request()->is('church/'.$page->slug); @endphp
        <li class="leading-none">
          <a class="inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isActive ? 'bg-white/20 text-white font-bold shadow-sm' : 'text-white/85 font-medium hover:text-white hover:bg-white/5' }}"
             href="/church/{{$page->slug}}" wire:navigate @if($isActive) aria-current="page" @endif>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
        @auth
        @php $isActive = request()->is('church/songs*'); @endphp
        <li class="leading-none">
          <a class="inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isActive ? 'bg-white/20 text-white font-bold shadow-sm' : 'text-white/85 font-medium hover:text-white hover:bg-white/5' }}"
             href="{{ route('church.songs.index') }}" wire:navigate @if($isActive) aria-current="page" @endif>
            Songs
          </a>
        </li>
        @endauth
      </ul>
    </li>

    <li>
      <div class="flex justify-center border-b border-white/15 pb-4">
        <a class="inline-flex items-center justify-center gap-2 font-display text-lg font-normal no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isCommunitySection ? 'text-white font-semibold' : 'text-white/80' }}"
           href="/community" wire:navigate @if($isCommunitySection) aria-current="page" @endif>
          <x-heroicon-s-user-group class="h-5 w-5 shrink-0" aria-hidden="true" />
          <span>Community</span>
        </a>
      </div>
      <ul class="mt-5 space-y-4">
        @foreach ($pages as $page)
        @if ($page->area->value == 'community')
        @php $isActive = request()->is('community/'.$page->slug); @endphp
        <li class="leading-none">
          <a class="inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isActive ? 'bg-white/20 text-white font-bold shadow-sm' : 'text-white/85 font-medium hover:text-white hover:bg-white/5' }}"
             href="/community/{{$page->slug}}" wire:navigate @if($isActive) aria-current="page" @endif>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
      </ul>
    </li>

    @if ($user)
    @php $isActive = request()->is('church/members*'); @endphp
    <li class="md:col-span-3">
      <div class="flex justify-center border-t border-white/10 pt-2">
        <a class="inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark {{ $isActive ? 'bg-white/20 text-white font-bold shadow-sm' : 'text-white/85 font-medium hover:text-white hover:bg-white/5' }}"
           href="/church/members" wire:navigate @if($isActive) aria-current="page" @endif>
          Members
        </a>
      </div>
    </li>
    @endif
    </ul>
  </nav>
</div>
