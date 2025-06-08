<?php

namespace Crockenhill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
        return Gate::allows('edit-pages');
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
            'area' => 'required|string|in:christ,church,community',
            'navigation-radio' => 'required|string|in:yes,no',
            'heading-image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp,svg|max:2048',
        ];
    }
}
