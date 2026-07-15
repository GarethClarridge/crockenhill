<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\VideoProcessingOptions;
use App\Enums\MediaType;
use App\Services\Processing\MediaValidationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ProcessMediaRequest extends MediaProcessingRequest
{
    private ?MediaValidationService $validationService = null;

    private function validationService(): MediaValidationService
    {
        return $this->validationService ??= $this->container->make(MediaValidationService::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->route('type') ?? $this->input('type');
        $mediaType = is_string($type) ? MediaType::tryFrom($type) : null;
        $validation = $this->validationService();

        $fileRules = $mediaType instanceof MediaType
            ? $validation->rulesForType($mediaType)
            : ['file' => ['required', 'file']];

        return [
            ...$fileRules,
            // Security: string type constraint is enforced before the enum rule to ensure
            // the input is correctly typed early in the validation lifecycle.
            'type' => [$this->route('type') ? 'nullable' : 'required', 'string', 'max:20', Rule::enum(MediaType::class)],
            ...VideoProcessingOptions::validationRules($mediaType),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $type = $this->route('type') ?? $this->input('type');
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
