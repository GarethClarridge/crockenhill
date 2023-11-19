@extends('layouts/page')

@section('dynamic_content')

  <div class="my-6 space-y-3">
    
    @can ('edit-sermons')
      <x-button link="/christ/sermons">
        Edit sermons
      </x-button>
    @endcan
    <x-button link="/church/members/songs">
      View song list
    </x-button>
    @can ('edit-pages')
      <x-button link="/church/members/pages">
        Edit pages
      </x-button>
    @endcan

    <form action="/logout" method="post">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">
      <button 
        type="submit" 
        name="logout" 
        class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all" 
        role="button">
        Log out
      </button>
    </form>
  </div>

@stop
