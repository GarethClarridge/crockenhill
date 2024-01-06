<!-- Alpine Plugins -->
<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<!-- Alpine Core -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>


<div class="w-100 text-white grid grid-cols-7 lg:grid-cols-12 justify-between bg-cbc-pattern bg-cover">

  <a class="p-2" href="/">
    <img src="/images/White.png" class="inline-block align-top max-h-8" alt="Crockenhill Baptist Church logo">
  </a>

  <a class="font-display col-span-5 text-xl min-[400px]:text-2xl text-center flex" href="/">
    <span class="inline-block align-middle pb-1 my-auto mx-auto">
      Crockenhill Baptist Church
    </span>
  </a>

  <div class="hidden w-100 lg:block col-span-5 flex my-auto pb-1">
    <ul class="mx-auto flex font-display text-l fill-white">
      <li>
        <a class="px-8 py-2 flex justify-between items-center fill-white" href="/christ">
          <x-icon-cross class="h-4 w-4 mr-1" />
          <span class="">Christ</span>
        </a>
      </li>

      <li>
        <a class="px-8 py-2 flex justify-between items-center fill-white" href="/church">
          <x-icon-church class="h-5 w-5 mr-1" />
          <span class="">Church</span>
        </a>
      </li>

      <li>
        <a class="px-8 py-2 flex justify-between items-center fill-white" href="/community">
          <x-heroicon-s-user-group class="h-5 w-5 mr-1" />
          <span class="">Community</span>
        </a>
      </li>
    </ul>
  </div>

  <button class="inline-block align-right select-none font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline ms-4 text-right flex items-center justify-end" type="button" role="button" aria-label="Navigation" @click="expanded = ! expanded">
    <x-heroicon-m-bars-3 class="h-6 w-6" />
  </button>

  <div class="w-100 lg:hidden col-span-8 flex bg-gradient-to-r from-green-100 to-emerald-100 text-emerald-950 fill-emerald-950 py-2">
    <ul class="mx-auto flex font-display">
      <li>
        <a class="px-8 py-2 flex justify-between items-center" href="/christ">
          <x-icon-cross class="h-4 w-4 mr-1" />
          <span class="">Christ</span>
        </a>
      </li>

      <li>
        <a class="px-8 py-2 flex justify-between items-center" href="/church">
          <x-icon-church class="h-5 w-5 mr-1" />
          <span class="">Church</span>
        </a>
      </li>

      <li>
        <a class="px-8 py-2 flex justify-between items-center" href="/community">
          <x-heroicon-s-user-group class="h-5 w-5 mr-1" />
          <span class="">Community</span>
        </a>
      </li>
    </ul>
  </div>



</div>

<div x-show="expanded" x-collapse class="absolute z-10 backdrop-blur-sm bg-gradient-to-r from-green-100/80 to-emerald-100/80 w-screen font-display p-6 leading-loose text-lg" tabindex="-1" x-cloak>
  <ul class="grid grid-cols-3 text-center mt-3">

    <li class="">
      <a class="bg-gradient-to-r from-teal-600 to-teal-800 text-white fill-white px-6 py-3 rounded-md text-xl inline-block" href="/christ">
        <div class="flex items-center">
          <x-icon-cross class="h-4 w-4 mr-1" />
          <span class="">Christ</span>
        </div>
      </a>
      <ul class="mt-6">
        @foreach ($pages as $page)
        @if ($page->area == 'christ')
        <li class="leading-none mb-6">
          <a class="" href="/christ/{{$page->slug}}">
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
      </ul>
    </li>

    <li class="">
      <a class="bg-gradient-to-r from-teal-600 to-teal-800 text-white fill-white px-6 py-3 rounded-md text-xl inline-block" href="/church">
        <div class="flex items-center">
          <x-icon-church class="h-5 w-5 mr-1" />
          <span class="">Church</span>
        </div>
      </a>
      <ul class="mt-6">
        @foreach ($pages as $page)
        @if ($page->area == 'church')
        <li class="leading-none mb-6">
          <a class="" href="/church/{{$page->slug}}">
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
      </ul>
    </li>

    <li class="">
      <a class="bg-gradient-to-r from-teal-600 to-teal-800 text-white fill-white px-6 py-3 rounded-md text-xl inline-block" href="/christ">
        <div class="flex items-center">
          <x-heroicon-s-user-group class="h-5 w-5 mr-1" />
          <span class="">Community</span>
        </div>
      </a>
      <ul class="mt-6">
        @foreach ($pages as $page)
        @if ($page->area == 'community')
        <li class="leading-none mb-6">
          <a class="" href="/community/{{$page->slug}}">
            {{$page->heading}}
          </a>
        </li>
        @endif
        @endforeach
      </ul>
    </li>

    @if ($user)
    <li class="">
      <a class="" href="/church/members">
        Members
      </a>
    </li>
    @endif
  </ul>
</div>