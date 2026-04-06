<?php

namespace App\Http\Requests;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\MediaValidationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessMediaRequest extends FormRequest
{
    private ?MediaValidationService $validationService = null;

    private function validationService(): MediaValidationService
    {
        return $this->validationService ??= $this->container->make(MediaValidationService::class);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->can('create', \App\Models\Sermon::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->input('type');
        $mediaType = is_string($type) ? MediaType::tryFrom($type) : null;
        $validation = $this->validationService();

        $fileRules = $mediaType instanceof MediaType
            ? $validation->rulesForType($mediaType)
            : ['file' => 'required|file'];

        return [
            ...$fileRules,
            'type' => ['required', Rule::enum(MediaType::class)],
            'auto_trim' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(
                    $mediaType !== MediaType::Video || ! (bool) config('media-processing.video_auto_trim.enabled', true)
                ),
            ],
            'video_processing_mode' => [
                'sometimes',
                'string',
                Rule::in([
                    MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
                    MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                ]),
                Rule::prohibitedIf(
                    $mediaType !== MediaType::Video || ! (bool) config('media-processing.video_auto_trim.enabled', true)
                ),
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
        $type = $this->input('type');
        $mediaType = is_string($type) ? MediaType::tryFrom($type) : null;
        $validation = $this->validationService();

        $maxSize = $mediaType instanceof MediaType
            ? $validation->maxFileSizeForDisplay($mediaType)
            : '100MB';
        $extensions = $mediaType instanceof MediaType
            ? $validation->allowedExtensionsForDisplay($mediaType)
            : 'MP3, WAV, M4A, MP4, MOV, AVI, MKV';

        return [
            'file.required' => 'Please select a media file to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => "Invalid file type. Supported formats: {$extensions}.",
            'file.max' => "The file size must not exceed {$maxSize}.",
            'type.required' => 'Please specify the media type.',
            'type.enum' => 'The media type must be '.implode(', ', MediaType::values()).'.',
            'auto_trim.prohibited' => 'Auto-trim is only available for sermon video uploads while the feature is enabled.',
            'video_processing_mode.in' => 'The selected video processing mode is invalid.',
            'video_processing_mode.prohibited' => 'Video processing mode is only available for sermon video uploads while the feature is enabled.',
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
            'auto_trim' => 'auto-trim option',
            'video_processing_mode' => 'video processing mode',
        ];
    }
}
