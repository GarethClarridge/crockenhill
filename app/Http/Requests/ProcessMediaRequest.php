<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessMediaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Sermon::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:mp3,wav,m4a,mp4,mov,avi,mkv|max:2097152',
            'type' => 'required|in:audio,video,livestream',
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
            'file.required' => 'Please select a media file to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The file must be an audio or video file (mp3, wav, m4a, mp4, mov, avi, mkv).',
            'file.max' => 'The file size must not exceed 2GB.',
            'type.required' => 'Please specify the media type.',
            'type.in' => 'The media type must be audio, video, or livestream.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => 'media file',
        ];
    }
}
