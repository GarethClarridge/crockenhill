@if (isset($slug))
    @can ('edit-pages')
        <form class="m-6" action="/church/members/pages/{{$slug}}" method="POST">
            <input type="hidden" name="_method" value="DELETE">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <x-button link="/church/members/pages/{{$slug}}/edit">
                <i class="fas fa-pencil-alt mr-2"></i>
                Edit page
            </x-button>
        </form>
    @endcan
@endif