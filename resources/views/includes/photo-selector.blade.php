<div class="photo-selector">
  <div class="flex flex-wrap ">
    <div class="w-full">
      <h2 class="mb-5">Add a photo</h2>
    </div>

    @foreach ($photos as $photo)

    <div class="flex flex-col">
      <div class="relative flex flex-col min-w-0 rounded break-words border bg-white border-1 border-gray-300 p-0">
        <img class="w-full rounded rounded-t" src="{{$photo_directory}}/{{$photo}}" alt="">
        <div class="flex-auto p-6">
          <p class="mb-0">
            Copy this into the markdown content box:
            <br>
            ![
            altText
            ](
            {{$photo_directory}}/{{$photo}}
            )
          </p>
        </div>
      </div>
    </div>

    @endforeach

  </div>
</div>