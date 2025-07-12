@extends('layouts/page')

@section('dynamic_content')

  <div class="px-6 grid grid-cols-1 gap-2 max-w-2xl mx-auto mt-6">
    
    @can ('edit-sermons')
      <x-button link="/christ/sermons">
        Edit sermons
      </x-button>
    @endcan
    @can ('edit-meetings')
      <x-button link="/church/members/meetings">
        Edit meetings
      </x-button>
    @endcan
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
