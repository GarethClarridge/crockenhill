@extends('page')

@section('dynamic_content')

    {!! Form::model($sermon, array('method' => 'put', 'action' => array('SermonController@update', $sermon->slug), 'role' => 'form')) !!}

        <div class="form-group">
            {!! Form::label('title', 'Title') !!}
            {!! Form::text('title', $value = null, array('class' => 'form-control')) !!}
        </div>

        <div class="form-group">
            {!! Form::label('date', 'Date') !!}
            <input type="date" class="form-control" id="date" name="date">
        </div>

        <div class="form-group">
            {!! Form::label('series', 'Series') !!}
            <select class="form-control" id="series" name="series">
                @foreach ($series as $s)
                    @if ($s === NULL)
                    <option value="NULL">No series</option>
                    @else
                    <option value="{!!$s!!}">{!!$s!!}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="form-group">
            {!! Form::label('new_series', 'New Series (if necessary)') !!}
            {!! Form::text('new_series', $value = null, array('class' => 'form-control')) !!}
        </div>

        <div class="form-group">
            {!! Form::label('reference', 'Reference') !!}
            {!! Form::text('reference', $value = null, array('class' => 'form-control')) !!}
        </div>

        <div class="form-group">
            {!! Form::label('preacher', 'Preacher') !!}
            {!! Form::text('preacher', $value = null, array('class' => 'form-control')) !!}
        </div>

        <div class="form-actions">
            {!! Form::submit('Save', array('class' => 'btn btn-success btn-save btn-large')) !!}
            <a href="{!! URL::route('members.sermons.index') !!}" class="btn btn-large">Cancel</a>
        </div>

    {!! Form::close() !!}
 
@stop
