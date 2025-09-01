<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SermonVideoUploadRequest extends FormRequest
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
        $maxFileSize = config('livestream-processing.max_file_size', 2147483648);
        $maxFileSizeKB = $maxFileSize / 1024;

        $supportedFormats = config('livestream-processing.supported_formats', [
            'mp4', 'mov', 'avi', 'mkv'
        ]);

        return [
            'file' => [
                'required',
                'file',
                'mimes:' . implode(',', $supportedFormats),
                'max:' . $maxFileSizeKB,
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
        $maxFileSizeMB = config('livestream-processing.max_file_size', 2147483648) / (1024 * 1024);
        $supportedFormats = config('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);

        return [
            'file.required' => 'Please select a video file to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The uploaded file must be one of the following types: ' . implode(', ', $supportedFormats) . '.',
            'file.max' => "The video file may not be greater than {$maxFileSizeMB}MB.",
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
            'file' => 'video file',
        ];
    }
}
