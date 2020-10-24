<?php namespace Crockenhill\Http\Controllers;

class MeetingController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
{
		return view('top-level-page');
	}


	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
		//
	}


	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
		//
	}


	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($slug)
	{
  	$meeting 			= \Crockenhill\Meeting::where('slug', $slug)->first();
    $type					= $meeting->type;
    $starttime		= $meeting->StartTime;
    $endtime			= $meeting->EndTime;
    $day 					= $meeting->day;
    $location			= $meeting->location;
    $who 					= $meeting->who;
    $phone				= $meeting->LeadersPhone;
    $email				= $meeting->LeadersEmail;

    //Photos
    if ($meeting->pictures === '1') {
    	$filelist = scandir($_SERVER['DOCUMENT_ROOT'].'/images/meetings/'.$slug);
    	$photos 	= array_slice($filelist, 2);
    } else {
    	$photos = '';
    }

		return view('meetings.meeting', array(
		    'slug'          => $slug,
        'type'					=> $type,
        'starttime'			=> $starttime,
        'endtime'				=> $endtime,
        'day'						=> $day,
        'location'			=> $location,
        'who'						=> $who,
        'phone'					=> $phone,
        'email'					=> $email,
        'photos'				=> $photos
		));
	}


	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($id)
	{
		//
	}


	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($id)
	{
		//
	}


	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
		//
	}


}
