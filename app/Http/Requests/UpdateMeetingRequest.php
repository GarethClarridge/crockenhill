<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Meeting; // Required for policy check
use Illuminate\Validation\Rules\Enum as EnumRule; // Renamed to avoid conflict
use App\Enums\MeetingType; // Added Enum import

class UpdateMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Assuming updating meetings requires admin privileges.
        // This replicates the middleware protection on the controller route.
        // $meeting = $this->route('meeting'); // Get the meeting instance from the route
        // return $this->user()->can('update', $meeting);
        // For now, this matches the 'admin' middleware which is not standard Laravel policy based.
        // The 'admin' middleware is EnsureUserIsAdmin.php which checks $request->user()->is_admin
        return $this->user() && $this->user()->is_admin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $meetingId = $this->route('meeting') instanceof Meeting ? $this->route('meeting')->id : $this->route('meeting');

        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('meetings', 'slug')->ignore($meetingId),
            ],
            'type' => ['required', new EnumRule(MeetingType::class)],
            'day' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'who' => 'nullable|string|max:255',
            'pictures' => 'required|boolean',
            // Adding other fields from migration, similar to StoreMeetingRequest
            'StartTime' => 'nullable|date_format:H:i:s',
            'EndTime' => 'nullable|date_format:H:i:s',
            'LeadersPhone' => 'nullable|string|max:10',
            'LeadersEmail' => 'nullable|email|max:50',
        ];
    }
}
