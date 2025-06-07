<?php namespace Crockenhill\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Crockenhill\Meeting;
// Preserving original use statement if it was Controller
use Crockenhill\Http\Controllers\Controller;

class MeetingController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
{
        // The view 'full-width-pages/community' likely expects a $page object
        // based on the ViewServiceProvider logic for 'layouts/page'
        $page = \Crockenhill\Page::where('slug', 'community')->firstOrFail();
		return view('full-width-pages/community', compact('page'));
	}


	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
        // Assuming a view exists at 'meetings.create' or 'community.create'
        return view('meetings.create');
	}


	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store(Request $request)
	{
        // Temporarily dump request to see if method is hit
        // dd($request->all());

        // Basic validation - adapt as needed
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:meetings,slug,NULL,id', // Adjusted for store
            'type' => 'nullable|string',
            'description' => 'nullable|string',
            'day' => 'nullable|string', // Uncommented
            'StartTime' => 'nullable|date_format:H:i:s', // Uncommented
            'EndTime' => 'nullable|date_format:H:i:s|after_or_equal:StartTime', // Uncommented
            'location' => 'nullable|string', // Uncommented
            'who' => 'nullable|string', // Uncommented
            'LeadersPhone' => 'nullable|string', // Uncommented
            'LeadersEmail' => 'nullable|email', // Uncommented
            'pictures' => 'nullable|string', // Uncommented
        ]);

        // Generate slug from the original request name if slug is not provided or empty
        if (empty($validatedData['slug']) && $request->filled('name')) {
            $validatedData['slug'] = Str::slug($request->input('name'));
        } elseif (empty($validatedData['slug'])) {
            // Fallback if name is also not provided (though validation should prevent this if 'name' is required)
            $validatedData['slug'] = 'meeting-' . uniqid();
        }

        // Ensure unique slug
        if (Meeting::where('slug', $validatedData['slug'])->exists()) {
            $validatedData['slug'] .= '-' . uniqid();
        }

        $meeting = Meeting::create($validatedData);

        return redirect()->route('community.index')->with('message', 'Meeting created successfully!');
	}


	/**
	 * Display the specified resource.
	 *
	 * @param  \Crockenhill\Meeting  $meeting  // Changed to use Route-Model Binding
	 * @return Response
	 */
	public function show(Meeting $meeting) // Changed parameter
	{
    // Data is now directly available from the $meeting object due to route-model binding.
    // $type					= $meeting->type;
    // $starttime		= $meeting->StartTime;
    // $endtime			= $meeting->EndTime;
    // $day 					= $meeting->day;
    // $location			= $meeting->location;
    // $who 					= $meeting->who;
    // $phone				= $meeting->LeadersPhone;
    // $email				= $meeting->LeadersEmail;

    //Photos - Simplified for now, assuming 'pictures' field might hold a relevant value or flag
    $photos = []; // Default to empty array
    if (!empty($meeting->pictures) && is_dir(public_path('images/meetings/' . $meeting->slug))) {
        // Basic check if a directory by slug name exists and 'pictures' field is set
        // This logic would need to be more robust in a real app
	$filelist = scandir(public_path('images/meetings/' . $meeting->slug));
	$photos 	= array_slice($filelist, 2); // Remove . and ..
    }

		return view('meetings.meeting', [
		    'meeting'       => $meeting,
            'heading'       => $meeting->name,
            'slug'          => $meeting->slug,
            'type'			=> $meeting->type,
            'starttime'		=> $meeting->StartTime,
            'endtime'		=> $meeting->EndTime,
            'day'			=> $meeting->day,
            'location'		=> $meeting->location,
            'who'			=> $meeting->who,
            'phone'			=> $meeting->LeadersPhone,
            'email'			=> $meeting->LeadersEmail,
            'photos'		=> $photos,
            'description'   => Str::limit($meeting->description, 160), // For meta description
            'content'       => $meeting->description // Main content for the page layout
		]);
	}


	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($id)
	{
        $meeting = Meeting::findOrFail($id);
        // Assuming a view exists at 'community.edit' or 'meetings.edit'
        return view('meetings.edit', compact('meeting'));
	}


	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update(Request $request, $id)
	{
        $meeting = Meeting::findOrFail($id);

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            // Slug validation: unique, ignoring current model
            'slug' => 'nullable|string|unique:meetings,slug,' . $meeting->id,
            'type' => 'nullable|string',
            'description' => 'nullable|string',
            'day' => 'nullable|string',
            'StartTime' => 'nullable|date_format:H:i:s',
            'EndTime' => 'nullable|date_format:H:i:s|after_or_equal:StartTime',
            'location' => 'nullable|string',
            'who' => 'nullable|string',
            'LeadersPhone' => 'nullable|string',
            'LeadersEmail' => 'nullable|email',
            'pictures' => 'nullable|string',
        ]);

        if (empty($validatedData['slug']) && $request->name !== $meeting->name) {
            $validatedData['slug'] = Str::slug($validatedData['name']);
        }
         // Ensure unique slug if automatically generated or changed
        if (isset($validatedData['slug']) && Meeting::where('slug', $validatedData['slug'])->where('id', '!=', $meeting->id)->exists()) {
            $validatedData['slug'] .= '-' . uniqid();
        }

        $meeting->update($validatedData);

        return redirect()->route('community.index')->with('message', 'Meeting updated successfully!');
	}


	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
        $meeting = Meeting::findOrFail($id);
        $meeting->delete();

        return redirect()->route('community.index')->with('message', 'Meeting deleted successfully!');
	}


}
