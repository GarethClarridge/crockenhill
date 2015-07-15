@extends('pages.page')

@stop

@section('dynamic_content')

{{-- Details --}}

  <div class="full-width meeting-details">
    <div class="row">
      <div class="col-md-12">
        <table>      
          <tbody>
            @if ($day != '') 
              <tr>
                <th scope="row">
                  <span class="glyphicon glyphicon-calendar"> </span>
                </th>
                <td>
                  {{$day}}
                </td>
              </tr>
            @endif
            @if ($starttime != '')
              <tr>          
                <th scope="row">
                  <span class="glyphicon glyphicon-time"> </span>
                </th>        
                <td>
                  {{date('g:ia', strtotime($starttime))}}
                  @if ($endtime != '')
                    - {{date('g:ia', strtotime($endtime))}}
                  @endif
                </td>        
              </tr>
            @endif
            @if ($location != '')
              <tr>          
                <th scope="row">
                  <span class="glyphicon glyphicon-map-marker"> </span>
                </th>          
                <td>{{ $location }}</td>       
              </tr> 
            @endif
            @if ($who != '')
              <tr>          
                <th scope="row">
                  <span class="glyphicon glyphicon-user"> </span>
                </th>          
                <td>{{$who}}</td>        
              </tr>
            @endif  
            @if ($phone != '')
              <tr>          
                <th scope="row">
                  <span class="glyphicon glyphicon-phone-alt"> </span>
                </th>          
                <td>{{$phone}}</td>        
              </tr>
            @endif 
            @if ($email != '')
              <tr>          
                <th scope="row">
                  <span class="glyphicon glyphicon-envelope"> </span>
                </th>          
                <td>{{$email}}</td>        
              </tr>
            @endif     
          </tbody>    
        </table>
      </div>
    </div>
  </div>

{{-- Child Protection --}}

  @if ($type === 'ChildrenAndYoungPeople' 
        || $slug === 'sunday-services')
    <hr>
    <small>
      All activities at the church are carried out in accordance with our 
      <a href="/media/documents/ChildProtection.pdf">Child Protection Policy</a>
      and our our 
      <a href="/media/documents/positive-behaviour-policy.pdf">Positive Behaviour Policy</a>.
    </small>
  @endif

@stop
