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
    if (\Gate::denies('see-member-content')) {
      abort(403);
    }

    // Get documents
    $documents = \Crockenhill\Document::get();

    return view('documents.index', array(
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
    if (\Gate::denies('edit-documents')) {
      abort(403);
    }

    return view('documents.create');
	}


	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
    if (\Gate::denies('edit-documents')) {
      abort(403);
    }

		// Get Input
		$title = \Request::input('title');
		$type = \Request::input('type');
		$file = \Request::file('document');
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
    if (\Gate::denies('edit-documents')) {
      abort(403);
    }
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
    if (\Gate::denies('edit-documents')) {
      abort(403);
    }
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
    if (\Gate::denies('edit-documents')) {
      abort(403);
    }
		//
	}


}
