<div class="edit-metadata mt-3">
  <div class="form-group">
    <label for="heading">Heading</label>
    <input class="form-control" id="heading" name="heading" type="text" value="{{$page->heading}}">
  </div>

  <div class="form-group">
    <label for="description">Description <small>(returned on Google searches)</small></label>
    <input class="form-control" id="description" name="description" type="text" value="{{$page->description}}">
  </div>

  <div class="form-group">
    <label for="area">Website section</label>
    <select class="form-control" name="area" value="{{$page->area}}">
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
  </div>
</div>
