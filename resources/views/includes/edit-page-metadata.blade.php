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
      @if ($page->area == 'church')
        <option value="church" selected>About us</option>
      @else
        <option value="church">About us</option>
      @endif

      @if ($page->area == 'community')
        <option value="community" selected>Community</option>
      @else
        <option value="community">Community</option>
      @endif

      @if ($page->area == 'find-us')
        <option value="find-us" selected>Find us</option>
      @else
        <option value="find-us">Find us</option>
      @endif

      @if ($page->area == 'contact-us')
        <option value="contact-us" selected>Contact us</option>
      @else
        <option value="contact-us">Contact us</option>
      @endif

      @if ($page->area == 'sermons')
        <option value="sermons" selected>Sermons</option>
      @else
        <option value="sermons">Sermons</option>
      @endif

      @if ($page->area == 'members')
        <option value="members" selected>Members</option>
      @else
        <option value="members">Members</option>
      @endif
    </select>
  </div>
</div>
