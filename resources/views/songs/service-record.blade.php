@extends('page')

@section('dynamic_content')

  <form action="/members/songs/service-record" method="post">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <div class="form-group">
      <label for="date" class="control-label">Date</label>
      <input type="date" name="date" value="{{ $next_service_upload_date }}" class="form-control">

      @foreach ($services as $key => $value)

        <h3>{{$value}} Service</h3>
        <div class="row">

          @for ($i = 1; $i < 6; $i++)
            <div class="col-xs-12">
              <h4>Song {{$i}}</h4>
            </div>
            <div class="col-xs-3">
              <label for="{{$key.'m'.$i.'-number'}}">Praise Number</label>
              <input type="text" name="{{$key.'m'.$i.'-number'}}" value="" class="form-control">
            </div>
            <div class="col-xs-9">
              <label for="{{$key.'m'.$i.'-title'}}">NIP title</label>
              <select class="form-control" name="{{$key.'m'.$i.'-title'}}">
                <option value>Please select...</option>
                @foreach ($nips as $nip)
                  <option value="{{$nip}}">{{$nip}}</option>
                @endforeach
              </select>
            </div>
          @endfor

          <div class="col-xs-12">
            <br>
            <div class="panel-group" id="accordion" role="tablist" aria-multiselectable="true">
              <div class="panel panel-default">
                <div class="panel-heading" role="tab" id="headingOne">
                  <h4 class="panel-title">
                    <a class="collapsed" data-toggle="collapse" data-parent="#accordion" href="#collapse{{$key}}" aria-expanded="false" aria-controls="collapse{{$key}}">                      Need more songs? &nbsp
                      <span class="glyphicon glyphicon-collapse-down" aria-hidden="true"></span>
                    </a>
                  </h4>
                </div>
                <div id="collapse{{$key}}" class="panel-collapse collapse" role="tabpanel" aria-labelledby="headingTwo">
                  <div class="panel-body">

                    @for ($i = 6; $i < 10; $i++)
                      <div class="col-xs-12">
                        <h4>Song {{$i}}</h4>
                      </div>
                      <div class="col-xs-4">
                        <label for="{{$key.'m'.$i.'-number'}}">Praise Number</label>
                        <input type="text" name="{{$key.'m'.$i.'-number'}}" value="" class="form-control">
                      </div>
                      <div class="col-xs-8">
                        <label for="{{$key.'m'.$i.'-title'}}">NIP title</label>
                        <select class="form-control" name="{{$key.'m'.$i.'-title'}}">
                          <option value>Please select...</option>
                          @foreach ($nips as $nip)
                            <option value="{{$nip}}"></option>
                          @endforeach
                        </select>
                      </div>
                    @endfor

                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      @endforeach


      <br>
      <input class="btn btn-success btn-lg" type="submit" value="Save">
    </div>
  </form>

@stop
