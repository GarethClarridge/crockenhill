@props([
'sermon',
])

<div class="max-w-sm rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2">

  @if (($sermon->title != null))
  <a class="font-display text-4xl my-auto underline" href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">
    <h4 class="p-6 mx-6 mt-6">
      {{$sermon->title}}
    </h4>
  </a>
  @endif
  <ul class="mx-6 px-6 mb-6 pb-6 prose">
    @if (($sermon->date != null))
    <li class="my-2 flex items-center">
      <x-heroicon-s-calendar class="h-5 w-5 mr-2" />
      {{ date('j F Y', strtotime($sermon->date)) }}
    </li>
    @endif
    @if ($sermon->service != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-clock class="h-5 w-5 mr-2" />
      {{ \Illuminate\Support\Str::title($sermon->service) }}
    </li>
    @endif
    @if ($sermon->preacher != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-user class="h-5 w-5 mr-2" />
      <a href="/christ/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
    </li>
    @endif
    @if ($sermon->series != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-tag class="h-5 w-5 mr-2" />
      <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
    </li>
    @endif
    @if ($sermon->reference != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-book-open class="h-5 w-5 mr-2" />
      {{ $sermon->reference }}
    </li>
    @endif
  </ul>

  @can ('edit-sermons')
  <form method="POST" action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8" class="-mt-6 grid grid-cols-2">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <a href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-bl-md bg-cbc-pattern bg-cover focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
      <div class="flex items-center justify-center">
        <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" />
        Edit
      </div>
    </a>

    <button type="submit" class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-br-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
      <div class="flex items-center justify-center">
        <x-heroicon-s-trash class="h-6 w-6 mr-2" />
        Delete
      </div>
    </button>
  </form>
  @endcan
</div>