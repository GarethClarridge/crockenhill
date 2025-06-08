<?php

namespace Crockenhill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PostSermonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Gate::allows('edit-sermons');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:mp3|max:51200', // Max 50MB
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select an MP3 file to upload.',
            'file.mimes'    => 'The uploaded file must be an MP3.',
            'file.max'      => 'The sermon audio file may not be greater than 50MB.',
        ];
    }
}
