<?php namespace Crockenhill\Http\Controllers;

use Crockenhill\Http\Requests;
use Crockenhill\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DocumentController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{
    // Set information about page to enable lookup
    $slug = 'documents';
    $area = 'members';
    
    // Look up page
    $page = \Crockenhill\Page::where('slug', $slug)->first();

    // Set details
    $heading = 'Documents';
    $breadcrumbs = '<li class="active">'.$page->heading.'</li>';
    $content = $page->body;

    // Get documents
    $documents = \Crockenhill\Document::get();

    return view('documents.index', array(
        'slug'        => $slug,
        'heading'     => $heading,        
        'description' => '<meta name="description" content="{{$heading}}">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'content'     => $content,
        'documents'   => $documents,
    ));
	}


	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
// Set information about page to enable lookup
    $area = 'members';

    // Set details
    $heading = 'Create a New Document';
    $breadcrumbs = '<li class="active">'.$heading.'</li>';

    return view('documents.create', array(
        'slug'        => 'create',
        'heading'     => $heading,        
        'description' => '<meta name="description" content="{{$heading}}">',
        'area'        => $area,
        'breadcrumbs' => $breadcrumbs,
        'content'     => '',
    ));
	}


	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
		// Get Input
		$title = \Input::get('title');
		$type = \Input::get('type');
		$file = \Input::file('document');
		$filename = $file->getClientOriginalName();
		$filetype = $file->getClientOriginalExtension();
    $user = "Test";
    //Reinstate when Auth working
		//$user = \Auth::user()->username;

		// Save details to database
		$document = new \Crockenhill\Document;
		$document->title = $title;
		$document->type = $type;
		$document->filename = $filename;
		$document->filetype = $filetype;
		$document->owner = $user;
		$document->save();

		// Upload document
		$destinationPath = public_path().'/media/documents';
		$file->move($destinationPath, $filename);

		// Return user to index
		return redirect('/members/documents');
	}


	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($id)
	{
		//
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
