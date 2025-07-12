@extends('layouts/page')

@section('dynamic_content')
  <div class="px-6 max-w-2xl mx-auto mt-6">
    <x-button link="pages/create">
      Create a new page
    </x-button>
  </div>

  <x-h2>
    Existing pages
  </x-h2>

  <x-admin-table :headers="['Title', 'Section', 'Last edited', '']">
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
          <x-admin-actions 
            :editRoute="'/church/members/pages/' . $page->slug . '/edit'"
            :deleteRoute="'/church/members/pages/' . $page->slug"
            deleteConfirmMessage="Are you sure you want to delete this page?"
            layout="inline" />
        </td>
      </tr>
    @endforeach
  </x-admin-table>

@stop
