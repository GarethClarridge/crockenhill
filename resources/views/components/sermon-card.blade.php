@props([
'sermon',
])

<div class="max-w-sm rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2">

    @if (($sermon->title != null))
        <a class="font-display text-4xl my-auto underline" 
            href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">
            <h4 class="p-6 mx-6 mt-6">
                {{$sermon->title}}
            </h4>
        </a>
    @endif
    <ul class="mx-6 px-6 mb-6 pb-6 prose">
        @if (($sermon->date != null))
            <li class="my-2">
                <i class="far fa-calendar"></i>
                &nbsp; {{ date('j F Y', strtotime($sermon->date)) }}
            </li>
        @endif
        @if ($sermon->service != null)
            <li class="my-2">
                <i class="far fa-clock"></i>
                &nbsp; {{ \Illuminate\Support\Str::title($sermon->service) }}
            </li>
        @endif
        @if ($sermon->preacher != null)
            <li class="my-2">
                <i class="far fa-user"></i> &nbsp
                <a href="/christ/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
            </li>
        @endif
        @if ($sermon->series != null)
            <li class="my-2">
                <i class="fas fa-tag"></i> &nbsp
                <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
            </li>
        @endif
        @if ($sermon->reference != null)
            <li class="my-2">
                <i class="fas fa-book"></i> &nbsp
                {{ $sermon->reference }}
            </li>
        @endif
    </ul>

    @can ('edit-sermons')
        <form method="POST" 
                action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" 
                accept-charset="UTF-8" 
                class="-mt-6 grid grid-cols-2">
            <input type="hidden" 
                    name="_token" 
                    value="{{ csrf_token() }}">
            <a href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit"
                class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-bl-md bg-gradient-to-r from-cyan-500 to-blue-500 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
                Edit
                <i class="ms-2 fas fa-pencil-alt"></i>
            </a>

            <button type="submit" class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-br-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
                Delete
                <i class="ms-2 fas fa-trash"></i>
            </button>
        </form> 
    @endcan
</div>
