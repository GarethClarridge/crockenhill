<div class="photo-selector">
  <div class="row">
    <h2>Add a photo</h2>

    @foreach ($photos as $photo)

      <div class="col-sm-3 thumbnail text-center">
        <img class="img-responsive" src="{{$photo_directory}}/{{$photo}}" alt="">
        <div class="caption">
          <span>
            <i class="far fa-copy"></i> &nbsp
            Copy this into the textarea below:
            <br>
            ![
            altText
            ](
            {{$photo_directory}}/{{$photo}}
            )
            </span>
        </div>
      </div>

    @endforeach

  </div>
</div>
