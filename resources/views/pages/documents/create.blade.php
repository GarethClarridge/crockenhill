@extends('pages.page')

@section('dynamic_content')

	{{ Form::open(array('action' => 'DocumentController@store', 'method' => 'POST', 'files' => true)) }}

		<div class="form-group">
			{{ Form::label('title', 'Title') }}
			{{ Form::text('title', $value = null, array('class' => 'form-control')) }}
		</div>

			<div class="form-group">
			  {{ Form::label('document', 'Document') }}
			  {{ Form::file('document') }}
			</div>

		<div class="form-group">
			{{ Form::label('type', 'Document Type') }}
			{{ Form::select('type', array(
				''                  => 'Please select ...',
				'meeting'           => 'Church Meeting Information', 
				'bible-study-notes' => 'Bible Study Notes', 
				'rota'              => 'Rota',
				), $value = null, array('class' => 'form-control')) }}
		</div>

		<div class="form-actions">
			{{ Form::submit('Save', array('class' => 'btn btn-primary btn-lg btn-block')) }}
		</div>

	{{ Form::close() }}
 
@stop
