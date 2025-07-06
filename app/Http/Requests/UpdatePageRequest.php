<?php

namespace App\Http\Requests;

use App\Enums\PageArea;
use Illuminate\Foundation\Http\FormRequest; // Added for Enum validation
use Illuminate\Validation\Rules\Enum; // Added Enum import

/**
 * Form Request for validating and authorizing the update of an existing Page.
 * Handles the validation rules for all fields related to a page
 * and ensures the user has 'edit-pages' permission.
 */
class UpdatePageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Assuming the route parameter for the page model is 'page'
        return $this->user()->can('update', $this->route('page'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'heading' => 'required|string|max:255',
            'markdown' => 'required|string',
            'description' => 'nullable|string',
            'area' => ['required', new Enum(PageArea::class)],
            'navigation-radio' => 'required|string|in:yes,no',
            'heading-image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp,svg|max:2048',
        ];
    }
}
