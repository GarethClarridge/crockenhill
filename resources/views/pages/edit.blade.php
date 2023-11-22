@php
  use Illuminate\Support\Str;
@endphp

@extends('layouts/page')

@section('dynamic_content')

  <form class="" action="/church/members/pages/{{$page->slug}}" method="post" enctype="multipart/form-data">
    <input type="hidden" name="_method" value="PUT">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <div>
      @if (count($errors) > 0)
        <div class="relative px-3 py-3 mb-4 border rounded bg-red-200 border-red-300 text-red-800">
          <strong>Whoops!</strong> There were some problems:<br><br>
          <ul>
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="">
          <label class="block" for="heading">Heading</label>
          <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="heading" name="heading" type="text" value="{{$page->heading}}">

          <label class="block mt-6" for="description">Description <small>(displayed in page cards and possibly returned on Google searches)</small></label>
          <textarea class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="description" name="description" type="text">{{$page->description}}</textarea>

          <label class="block mt-6" for="area">Website section</label>
          <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" name="area" value="{{$page->area}}">
            @if ($page->area == 'christ')
              <option value="christ" selected>Christ</option>
            @else
              <option value="christ">Christ</option>
            @endif

            @if ($page->area == 'church')
              <option value="church" selected>Church</option>
            @else
              <option value="church">Church</option>
            @endif

            @if ($page->area == 'community')
              <option value="community" selected>Community</option>
            @else
              <option value="community">Community</option>
            @endif
          </select>

          @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
            <div>
              <p class="mt-6 mb-1">Heading image</p>
              <img src="{{$headingpicture}}" alt="{{$headingpicture}}" class="max-w-full h-auto" id="headingpicture">
            </div>
          @endif

          <div>
            <label class="block mt-6" for="heading-image">Upload a new heading image</label>
            <input name="heading-image" type="file" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 py-2 px-4 text-lg leading-normal rounded" id="heading-image" onchange=file_changed() aria-describedby="heading-image">
          </div>

      </div>

      <div class="grid grid-cols-2 gap-12 my-6">


        <div class="">
          <label class="block mb-3" for="markdown">Markdown content</label>
          @if ($page->markdown)
            <textarea class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" name="markdown" id="markdown-input" rows="20">{{trim($page->markdown)}}</textarea>
          @else
            <textarea class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" name="markdown" id="markdown-input" rows="20">{{trim($page->body)}}</textarea>
          @endif
        </div>

        <div class="">
          <h4 class="mb-3">
            Rendered content
          </h4>
          <div id="rendered-content" class="prose mt-1 block bg-white py-3 px-6 rounded-md border border-gray-300 shadow-sm ">
            {!!$page->body!!}
          </div>
        </div>

      </div>

      <div class="form-actions my-3">
        <div class="d-grid gap-2 mb-3">
          <input class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-green-500 hover:bg-green-600 py-3 px-4 leading-tight text-xl" type="submit" value="Save">
        </div>
        <div class="text-center">
          <a href="/church/members/pages/" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline btn-outline py-3 px-4 leading-tight text-xl">Cancel</a>
        </div>
      </div>

      <!-- @include('includes.photo-selector') -->
    </div>
  </form>


<script src="https://cdnjs.cloudflare.com/ajax/libs/showdown/1.6.0/showdown.min.js
" charset="utf-8"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js" charset="utf-8"></script>
<script type="text/javascript">

  function markdownInit() {
    var markdown      = document.getElementById("markdown-input");
    var render        = document.getElementById("rendered-content");
    markdown.onfocus = function blankRender() {
      render.innerHTML = '';
    }
    markdown.onblur = function markdown() {
      var converter     = new showdown.Converter();
      var markdown      = document.getElementById("markdown-input");
      render.innerHTML  = converter.makeHtml(markdown.value);
    }
  }

  window.onload = markdownInit();

  function file_changed(){
    var selectedFile = document.getElementById('heading-image').files[0];
    var img = document.getElementById('headingpicture')

    var reader = new FileReader();
    reader.onload = function(){
      img.src = this.result
    }
    reader.readAsDataURL(selectedFile);
  }
</script>

@stop
