@extends('page')

@section('dynamic_content')

  <form method="POST" action="/members/pages" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    <input type="hidden" name="_token" id="csrf-token" value="{{ Session::token() }}" />

    <div class="form-group">
      <label for="heading">Heading</label>
      <input class="form-control h1" id="heading" name="heading" type="text">
    </div>

    <div class="form-group">
      <label for="image">Header image</label>
      <input name="image" type="file" id="image">
    </div>

    <div class="form-group">
      <label for="area">Website area</label>
      <select class="form-control" id="area" name="area">
        <option value="about-us">About us</option>
        <option value="whats-on">What's on</option>
        <option value="find-us">Find us</option>
        <option value="contact-us">Contact us</option>
        <option value="sermons">Sermons</option>
        <option value="members">Members</option>
      </select>
    </div>

    <div class="form-group">
      <label for="description">Page description</label>
      <input class="form-control" name="description" type="text" id="description">
    </div>

    <div class="form-group">
      <label for="body">Page content</label>
      <textarea class="form-control" name="body" id="body"></textarea>
    </div>

    <div class="form-actions">
      <input class="btn btn-success btn-save btn-large" type="submit" value="Save">
      <a href="{!! URL::route('members.pages.index') !!}" class="btn btn-large">Cancel</a>
    </div>

  </form>
 
@stop
