<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutomatedSermonUploadRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxFileSize = config('media-processing.processing.max_file_size', 100 * 1024 * 1024);
        $maxFileSizeKB = $maxFileSize / 1024;

        $allowedMimeTypes = config('media-processing.processing.allowed_mime_types', [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/x-wav',
            'audio/mp4',
            'audio/m4a',
        ]);

        $allowedExtensions = config('media-processing.processing.allowed_extensions', [
            'mp3',
            'wav',
            'm4a',
            'mp4',
        ]);

        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', array_map(fn ($mime) => str_replace('audio/', '', $mime), $allowedMimeTypes)),
                'max:'.$maxFileSizeKB,
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxFileSizeMB = config('media-processing.processing.max_file_size', 100 * 1024 * 1024) / (1024 * 1024);
        $allowedExtensions = config('media-processing.processing.allowed_extensions', ['mp3', 'wav', 'm4a', 'mp4']);

        return [
            'file.required' => 'Please select an audio file to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The uploaded file must be one of the following types: '.implode(', ', $allowedExtensions).'.',
            'file.max' => "The sermon audio file may not be greater than {$maxFileSizeMB}MB.",
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
            'file' => 'audio file',
        ];
    }
}
