<div class="relative flex flex-col min-w-0 rounded break-words border bg-white border-1 border-gray-300 p-0">
  <div class="flex flex-wrap  g-0">
    @if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/images/sermons/' . $sermon->series . '.png'))
      <div class="sermon-icon md:w-1/2 pr-4 pl-4 ratio ratio-1x1">
        <img src="/images/sermons/{{$sermon->series}}.jpg" alt="{{$sermon->series}}">
      </div>
    @else
      <div class="sermon-icon md:w-1/2 pr-4 pl-4 flex items-center justify-center ratio ratio-1x1" style="background-image: url('/images/sermons/standard.jpg'); background-size:100% auto;">
        <span class="h1 mx-auto align-middle text-center flex items-center justify-center px-5">
          {{$sermon->series}}
        </span>
      </div>
    @endif
    <div class="md:w-1/2 pr-4 pl-4">
      <div class="flex-auto p-6 text-center">
        <ul class="flex flex-col pl-0 mb-0 border rounded border-gray-300 ">
          @if (($sermon->title != null))
            <li class="relative block py-3 px-6 -mb-px border border-r-0 border-l-0 border-gray-300 no-underline">
              <h3>
                <a class="my-auto" href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">
                  {{$sermon->title}}
                </a>
              </h3>
            </li>
          @endif
          @if (($sermon->date != null))
            <li class="relative block py-3 px-6 -mb-px border border-r-0 border-l-0 border-gray-300 no-underline">
              <i class="far fa-calendar"></i>
              &nbsp; {{ date('j F Y', strtotime($sermon->date)) }}
            </li>
          @endif
          @if ($sermon->service != null)
            <li class="relative block py-3 px-6 -mb-px border border-r-0 border-l-0 border-gray-300 no-underline">
              <i class="far fa-clock"></i>
              &nbsp; {{ \Illuminate\Support\Str::title($sermon->service) }}
            </li>
          @endif
          @if ($sermon->preacher != null)
            <li class="relative block py-3 px-6 -mb-px border border-r-0 border-l-0 border-gray-300 no-underline">
              <i class="far fa-user"></i> &nbsp
              <a href="/christ/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
            </li>
          @endif
          @if ($sermon->series != null)
            <li class="relative block py-3 px-6 -mb-px border border-r-0 border-l-0 border-gray-300 no-underline">
              <i class="fas fa-tag"></i> &nbsp
              <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
            </li>
          @endif
          @if ($sermon->reference != null)
            <li class="relative block py-3 px-6 -mb-px border border-r-0 border-l-0 border-gray-300 no-underline">
              <i class="fas fa-book"></i> &nbsp
              {{ $sermon->reference }}
            </li>
          @endif
        </ul>
        @can ('edit-sermons')
          <form method="POST" action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8" class="pt-3">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <div class="relative inline-flex align-middle">
              <a href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-blue-600 hover:bg-blue-600">
                <i class="fas fa-pencil-alt"></i> &nbsp
                Edit
              </a>

              <button type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-red-600 hover:bg-red-700">
                <i class="fas fa-trash"></i> &nbsp
                Delete
              </button>
            </div>
          </form>
        @endcan
      </div>
    </div>
  </div>
</div>
