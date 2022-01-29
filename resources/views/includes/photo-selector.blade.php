<div class="photo-selector">
  <div class="row">
    <div class="col-12">
      <h2 class="mb-5 text-white">Add a photo</h2>
    </div>

    @foreach ($photos as $photo)

      <div class="card-group">
        <div class="card p-0">
          <img class="card-img-top" src="{{$photo_directory}}/{{$photo}}" alt="">
          <div class="card-body">
            <p class="card-text">
              <i class="far fa-copy"></i> &nbsp
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
