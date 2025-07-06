<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule; // Renamed to avoid conflict with class Enum
use App\Enums\MeetingType; // Added Enum import

class StoreMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Assuming creating meetings requires admin privileges, similar to pages/sermons.
        // If a specific MeetingPolicy exists or needs to be created, this should use it.
        // For now, checking if the user has a general admin capability.
        // This replicates the middleware protection on the controller route.
        return $this->user() && $this->user()->is_admin;
        // A more robust way would be to create MeetingPolicy and use:
        // return $this->user()->can('create', \App\Models\Meeting::class);
        // For now, this matches the 'admin' middleware which is not standard Laravel policy based.
        // The 'admin' middleware is EnsureUserIsAdmin.php which checks $request->user()->is_admin
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => 'required|string|max:255|unique:meetings,slug',
            'type' => ['required', new EnumRule(MeetingType::class)],
            'day' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'who' => 'nullable|string|max:255',
            'pictures' => 'required|boolean',
            // The migration also has StartTime, EndTime, LeadersPhone, LeadersEmail
            // These are not in the controller's $request->validate() currently.
            // Adding them here as nullable if they are part of the form.
            // If they are not part of the form, they can be removed from here.
            'StartTime' => 'nullable|date_format:H:i:s', // Or H:i if seconds are not used
            'EndTime' => 'nullable|date_format:H:i:s',   // Or H:i
            'LeadersPhone' => 'nullable|string|max:10',  // Max length from migration
            'LeadersEmail' => 'nullable|email|max:50',   // Max length from migration
        ];
    }
}
