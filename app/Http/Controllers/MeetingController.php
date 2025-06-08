<?php namespace Crockenhill\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Crockenhill\Meeting;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule; // Added for unique rule in update
use Crockenhill\Services\MeetingImageService; // Added this
use Illuminate\Support\Facades\Gate; // Ensured this is active

class MeetingController extends Controller {

    private MeetingImageService $meetingImageService;

    public function __construct(MeetingImageService $meetingImageService)
    {
        $this->meetingImageService = $meetingImageService;
    }

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
{
		return view('full-width-pages/community');
	}


	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
        // Assuming 'edit-meetings' or a similar permission would be used.
        // For now, let's mirror 'edit-pages' or assume direct access if no specific meeting perm yet.
        // if (Gate::denies('edit-meetings')) { // Or 'edit-pages'
        //     abort(403);
        // }
        return view('meetings.create'); // Assumes this view will be created
	}


	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
    public function store(Request $request)
    {
        // if (Gate::denies('edit-meetings')) { // Or 'edit-pages'
        //     abort(403);
        // }

        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|unique:meetings,slug|max:75',
            'type' => 'required|string|in:SundayAndBibleStudies,ChildrenAndYoungPeople,Adults,Occasional',
            'day' => 'required|string|max:75',
            'location' => 'required|string|max:75',
            'who' => 'required|string|max:75',
            'StartTime' => 'nullable|date_format:H:i', // Assumes H:i format for time
            'EndTime' => 'nullable|date_format:H:i|after_or_equal:StartTime',
            'pictures' => 'nullable|boolean',
            'LeadersPhone' => 'nullable|string|max:10',
            'LeadersEmail' => 'nullable|email|max:50',
        ]);

        // Handle boolean for pictures if it comes as 'on' or similar from form
        $validatedData['pictures'] = $request->has('pictures');

        // If slug is not provided or needs to be auto-generated from title:
        // if (empty($validatedData['slug'])) {
        //     $validatedData['slug'] = Str::slug($validatedData['title']);
        //     // Re-validate slug for uniqueness if auto-generated, or handle potential collision.
        // }

        $meeting = Meeting::create($validatedData);

        Session::flash('message', $meeting->title . ' successfully created!');
        // Redirect to the new meeting's show page, using 'community' as the route name base
        return Redirect::route('community.show', $meeting->slug);
    }


	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($slug)
	{
	$meeting 			= \Crockenhill\Meeting::where('slug', $slug)->firstOrFail(); // Use firstOrFail to handle not found

    $title        = $meeting->title; // New title field
    $type					= $meeting->type;   // Existing ENUM type
    $starttime		= $meeting->StartTime;
    $endtime			= $meeting->EndTime;
    $day 					= $meeting->day;
    $location			= $meeting->location;
    $who 					= $meeting->who;
    $phone				= $meeting->LeadersPhone;
    $email				= $meeting->LeadersEmail;

    //Photos
    // Assuming 'pictures' is cast to boolean in the Meeting model
    if ($meeting->pictures) {
    	$filelist = scandir($_SERVER['DOCUMENT_ROOT'].'/images/meetings/'.$slug);
    	$photos 	= array_slice($filelist, 2);
    } else {
    	$photos = '';
    }

		return view('meetings.meeting', [ // Use short array syntax for consistency if preferred
		    'slug'          => $slug,
        'title'         => $title, // Pass the new title
        'type'					=> $type,
        'starttime'			=> $starttime,
        'endtime'				=> $endtime,
        'day'						=> $day,
        'location'			=> $location,
        'who'						=> $who,
        'phone'					=> $phone,
        'email'					=> $email,
        'photos'				=> $photos
		]);
	}


	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit(Meeting $community) // Using Route Model Binding
	{
        // if (Gate::denies('edit-meetings')) { // Or 'edit-pages'
        //     abort(403);
        // }
        // The variable $community (singular of resource name 'community') is injected by RMB
        return view('meetings.edit', ['meeting' => $community]);
	}


	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id // This will be Meeting $community due to RMB
	 * @return Response
	 */
    public function update(Request $request, Meeting $community) // Using Route Model Binding
    {
        // if (Gate::denies('edit-meetings')) { // Or 'edit-pages'
        //     abort(403);
        // }

        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                Rule::unique('meetings', 'slug')->ignore($community->id),
                'max:75'
            ],
            'type' => 'required|string|in:SundayAndBibleStudies,ChildrenAndYoungPeople,Adults,Occasional',
            'day' => 'required|string|max:75',
            'location' => 'required|string|max:75',
            'who' => 'required|string|max:75',
            'StartTime' => 'nullable|date_format:H:i',
            'EndTime' => 'nullable|date_format:H:i|after_or_equal:StartTime',
            'pictures' => 'nullable|boolean',
            'LeadersPhone' => 'nullable|string|max:10',
            'LeadersEmail' => 'nullable|email|max:50',
        ]);

        $validatedData['pictures'] = $request->has('pictures');

        $validatedData['pictures'] = $request->has('pictures');

        // Get the original slug BEFORE updating the model
        $oldSlug = $community->slug;

        $community->update($validatedData); // Model is updated here, $community->slug might now be new

        // Get the new slug AFTER updating the model
        $newSlug = $community->slug;

        // Check if the slug changed and rename the directory if it did
        if ($oldSlug !== $newSlug) {
            $this->meetingImageService->renameImageDirectory($oldSlug, $newSlug);
        }

        $message = $community->title . ' successfully updated!';

        // Refined redirect logic
        if ($oldSlug !== $newSlug) { // Slug was changed (checking again for redirect logic)
            $backUrl = Session::get('backUrl');
            Session::forget('backUrl');

            if ($backUrl) {
                $modifiedBackUrl = str_replace($oldSlug, $newSlug, $backUrl);
                return Redirect::to($modifiedBackUrl)->with('message', $message);
            } else {
                return Redirect::route('community.show', $newSlug)->with('message', $message);
            }
        } else { // Slug did not change
            $backUrl = Session::get('backUrl');
            Session::forget('backUrl');

            if ($backUrl && $backUrl !== url()->current()) {
                return Redirect::to($backUrl)->with('message', $message);
            } else {
                return Redirect::to(route('community.index'))->with('message', $message); // Default to index
            }
        }
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

    public function listAllMeetings() // : View  // Can add return type hint if using PHP 7.4+
    {
        // Authorize based on 'edit-pages' permission
        // This will throw an AuthorizationException (resulting in a 403 response) if fails.
        Gate::authorize('edit-pages');

        $meetings = Meeting::orderByRaw('COALESCE(title, slug) asc')->get();
        // Alternatively, for pagination:
        // $meetings = Meeting::orderBy('title', 'asc')->paginate(20);

        return view('meetings.admin-index', ['meetings' => $meetings]);
    }
}
