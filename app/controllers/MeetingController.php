<?php

class MeetingController extends \BaseController {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
{
		$slug 				= 'whats-on';
		$area 				= $slug;
  	$page 				= Page::where('slug', $slug)->first();
		$links 				= Meeting::where('slug', '!=', $slug)
							    	->orderBy(DB::raw('RAND()'))
							    	->take(8)
							    	->get();
    $heading 			= $page->heading;
    $headingpicture = '/images/headings/large/'.$slug.'.jpg';
    $breadcrumbs 	= '<li class="active">'.$page->heading.'</li>';
    $description 	= '<meta name="description" content="'.$page->description.'">';
    $content 			= $page->body;

		$this->layout->content = View::make('pages.page', array(
		    'slug'          => $slug,
		    'heading'       => $heading,	
		    'headingpicture'=> $headingpicture,	    
		    'description'   => $description,
        'area'					=> $area,
		    'breadcrumbs'   => $breadcrumbs,
		    'content'      	=> $content,
        'links'					=> $links
		));
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
		$area 				= 'whats-on';
  	$page 				= Meeting::where('slug', $slug)->first();
		$links 				= Meeting::where('slug', '!=', $slug)
							    	->orderBy(DB::raw('RAND()'))
							    	->take(4)
							    	->get();
    $heading 			= $page->heading;
    $headingpicture = '/images/headings/large/'.$slug.'.jpg';
    $breadcrumbs 	= '<li class="active">'.$page->heading.'</li>';
    $description 	= '<meta name="description" content="'.$page->description.'">';
    $content 			= $page->body;
    $type					= $page->type;
    $starttime		= $page->StartTime;
    $endtime			= $page->EndTime;
    $day 					= $page->day;
    $location			= $page->location;
    $who 					= $page->who;
    $phone				= $page->LeadersPhone;
    $email				= $page->LeadersEmail;

		$this->layout->content = View::make('pages.meetings.meeting', array(
		    'slug'          => $slug,
		    'heading'       => $heading,		
		    'headingpicture'=> $headingpicture,    
		    'description'   => $description,
        'area'					=> $area,
		    'breadcrumbs'   => $breadcrumbs,
		    'content'      	=> $content,
        'links'					=> $links,
        'type'					=> $type,
        'starttime'			=> $starttime,
        'endtime'				=> $endtime,
        'day'						=> $day,
        'location'			=> $location,
        'who'						=> $who,
        'phone'					=> $phone,
        'email'					=> $email
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
