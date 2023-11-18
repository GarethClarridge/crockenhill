@extends('layouts/page')

@section('dynamic_content')

<form class="mb-3" action="/church/members/pages" method="post">
  <input type="hidden" name="_token" value="{{ csrf_token() }}">

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

  <div class="edit-metadata mt-3 flex flex-wrap ">
        <div class="w-2/3">
          <div class="mb-3">
            <label for="heading">Heading</label>
            <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="heading" name="heading" type="text">
          </div>

          <div class="mb-3">
            <label for="description">Description <small>(returned on Google searches)</small></label>
            <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="description" name="description" type="text">
          </div>

          <div class="mb-3">
            <label for="area">Website section</label>
            <select class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="area">
              <option value="christ">Christ</option>
              <option value="church">Church</option>
              <option value="community">Community</option>
            </select>
          </div>
        </div>
        <div class="mb-3 w-1/3">
          <div>
            <label class="form-label" for="heading-image">Upload a new heading image</label>
            <input name="heading-image" type="file" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded py-2 px-4 text-lg leading-normal rounded" id="heading-image" onchange=file_changed() aria-describedby="heading-image">
          </div>
        </div>
      </div>

  <div class="flex flex-wrap ">
    <div class="w-1/2">
      <div class="mb-3">
        <label for="markdown">Markdown content</label>
        <textarea class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="markdown" id="markdown-input" rows="20"></textarea>
      </div>
    </div>

    <div class="w-1/2">
      <h4>
        Rendered content
      </h4>
      <div id="rendered-content">

      </div>
    </div>
  </div>

  <div class="form-actions">
    <div class="d-grid gap-2 mb-3">
      <input class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-green-500 hover:bg-green-600 py-3 px-4 leading-tight text-xl" type="submit" value="Save">
    </div>
    <div class="text-center">
      <a href="/church/members/pages/" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline py-3 px-4 leading-tight text-xl text-center">Cancel</a>
    </div>
  </div>
</form>

<!-- @include('includes.photo-selector') -->



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
</script>

@stop
