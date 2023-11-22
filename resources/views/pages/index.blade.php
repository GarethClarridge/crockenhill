@extends('layouts/page')

@section('dynamic_content')
  <x-button link="pages/create">
    Create a new page
  </x-button>

  <x-h2>
    Existing pages
  </x-h2>

  <div class="block w-full overflow-auto scrolling-touch">
    <table class="w-full max-w-full mb-4 bg-transparent table-hover">
      <thead>
        <tr>
          <th>Title</th>
          <th>Section</th>
          <th>Last edited</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($pages as $page)
          <tr>
            <td>
              @if ($page->area == $page->slug)
                <a href="/{{$page->slug}}">
              @else
                <a href="/{{$page->area}}/{{$page->slug}}">
              @endif
                  {{ $page->heading }}
                </a>
            </td>
            <td>{{ $page->area }}</td>
            <td>{{ $page->updated_at }}</td>
            <td>
              <form class="flex items-center" action="/church/members/pages/{{$page->slug}}" method="POST">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <div class="relative inline-flex align-middle">
                  <a href="/church/members/pages/{{$page->slug}}/edit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-green-500 hover:bg-green-600">
                    Edit
                  </a>
                  <button type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-red-600 hover:bg-red-700">
                    Delete
                  </button>
                </div>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

@stop
