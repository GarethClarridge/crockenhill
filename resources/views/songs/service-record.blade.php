@extends('pages.page')

@section('dynamic_content')

  {{ Form::open(array('url' => '/members/songs/service-record')) }}
    <div class="form-group">
      {{ Form::label('date', 'Date', array('class' => 'control-label')) }}
      {{ Form::input('date', 'date', $lastsunday, array('class'=>'form-control')) }}

      @foreach ($services as $key => $value)

        <h3>{{$value}} Service</h3>
        <div class="row">

          @for ($i = 1; $i < 6; $i++)
            <div class="col-xs-12">
              <h4>Song {{$i}}</h4>
            </div>
            <div class="col-xs-3">
              {{ Form::label($key.'m'.$i.'-number', 'Praise Number') }}
              {{ Form::text($key.'m'.$i.'-number', null, array('class' => 'form-control')) }}
            </div>
            <div class="col-xs-9">
              {{ Form::label($key.'m'.$i.'-title', 'NIP Title') }}
              {{ Form::select($key.'m'.$i.'-title', $nips, 'Select a song', array('class' => 'form-control')) }}
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
                        {{ Form::label($key.'m'.$i.'-number', 'Praise Number') }}
                        {{ Form::text($key.'m'.$i.'-number', null, array('class' => 'form-control')) }}
                      </div>
                      <div class="col-xs-8">
                        {{ Form::label($key.'m'.$i.'-title', 'NIP Title') }}
                        {{ Form::select($key.'m'.$i.'-title', $nips, 'Select a song', array('class' => 'form-control')) }}
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
      {{ Form::submit('Save', array('class' => 'btn btn-primary btn-lg btn-block')) }}
    </div>
  {{ Form::close() }}

@stop