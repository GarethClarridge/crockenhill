{{-- Alpine.js is automatically loaded by Livewire 3 --}}

@php
  $isChristSection = request()->is('christ') || request()->is('christ/*');
  $isChurchSection = request()->is('church') || request()->is('church/*');
  $isCommunitySection = request()->is('community') || request()->is('community/*');
@endphp

<div class="w-100 grid grid-cols-7 justify-between bg-cbc-pattern bg-cover text-white lg:grid-cols-12">

  <a class="p-2" href="/" wire:navigate>
    <img src="/svg/IconWhite.svg" class="inline-block max-h-8 align-top" alt="Crockenhill Baptist Church logo" width="30" height="32">
  </a>

  <a class="col-span-5 flex text-center font-display text-xl min-[400px]:text-2xl" href="/" wire:navigate>
    <span class="mx-auto my-auto inline-block align-middle pb-1">
      Crockenhill Baptist Church
    </span>
  </a>

  <div class="col-span-5 hidden h-full w-100 self-stretch lg:block">
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
    class="ms-4 flex items-center justify-end rounded px-3 py-1 text-right align-right font-normal leading-normal no-underline select-none whitespace-no-wrap focus:outline-none focus:ring-2 focus:ring-white transition-all duration-200"
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

<div x-show="expanded" x-transition id="mobile-menu" class="absolute z-30 w-screen bg-gradient-to-r from-cbc-teal/10 to-cbc-teal-light/10 p-6 font-display text-lg leading-loose backdrop-blur-sm" tabindex="-1" x-cloak>
  <ul class="mt-3 grid grid-cols-1 gap-8 text-center md:grid-cols-3">

    <li>
      <a class="inline-block rounded-md bg-gradient-to-r from-teal-600 to-teal-800 px-6 py-3 text-xl text-white fill-white" href="/christ" wire:navigate>
        <div class="flex items-center justify-center">
          <x-icon-cross class="mr-2 h-5 w-5" aria-hidden="true" />
          <span>Christ</span>
        </div>
      </a>
      <ul class="mt-6">
        @foreach ($pages as $page)
        @if ($page->area->value == 'christ')
        <li class="mb-6 leading-none">
          <a href="/christ/{{$page->slug}}" wire:navigate>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
        @auth
        <li class="mb-6 leading-none">
          <a href="{{ route('childrens-corner.index') }}" wire:navigate>
            Children's Corner
          </a>
        </li>
        @endauth
      </ul>
    </li>

    <li>
      <a class="inline-block rounded-md bg-gradient-to-r from-teal-600 to-teal-800 px-6 py-3 text-xl text-white fill-white" href="/church" wire:navigate>
        <div class="flex items-center justify-center">
          <x-icon-church class="mr-2 h-5 w-5" aria-hidden="true" />
          <span>Church</span>
        </div>
      </a>
      <ul class="mt-6">
        @foreach ($pages as $page)
        @if ($page->area->value == 'church')
        <li class="mb-6 leading-none">
          <a href="/church/{{$page->slug}}" wire:navigate>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
        @auth
        <li class="mb-6 leading-none">
          <a href="{{ route('church.songs.index') }}" wire:navigate>
            Songs
          </a>
        </li>
        @endauth
      </ul>
    </li>

    <li>
      <a class="inline-block rounded-md bg-gradient-to-r from-teal-600 to-teal-800 px-6 py-3 text-xl text-white fill-white" href="/community" wire:navigate>
        <div class="flex items-center justify-center">
          <x-heroicon-s-user-group class="mr-2 h-5 w-5" aria-hidden="true" />
          <span>Community</span>
        </div>
      </a>
      <ul class="mt-6">
        @foreach ($pages as $page)
        @if ($page->area->value == 'community')
        <li class="mb-6 leading-none">
          <a href="/community/{{$page->slug}}" wire:navigate>
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
      </ul>
    </li>

    @if ($user)
    <li>
      <a href="/church/members" wire:navigate>
        Members
      </a>
    </li>
    @endif
  </ul>
</div>
