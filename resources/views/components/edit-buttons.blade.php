@if (isset($slug))
@can ('edit-pages')
<form class="m-6" action="/church/members/pages/{{$slug}}" method="POST">
  <input type="hidden" name="_method" value="DELETE">
  <input type="hidden" name="_token" value="{{ csrf_token() }}">
  <x-button link="/church/members/pages/{{$slug}}/edit">
    <div class="flex items-center justify-center">
      <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" />



      Edit page
    </div>
  </x-button>
</form>
@endcan
@endif