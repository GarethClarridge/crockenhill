<?php

namespace App\Http\Requests;

use App\Services\MediaValidationService;
use Illuminate\Foundation\Http\FormRequest;

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
        $type = $this->input('type', 'audio');
        $validation = $this->validationService();

        $fileRules = in_array($type, $validation->supportedTypes(), true)
            ? $validation->rulesForType($type)
            : ['file' => 'required|file'];

        return [
            ...$fileRules,
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
        $type = $this->input('type', 'audio');
        $validation = $this->validationService();

        $maxSize = in_array($type, $validation->supportedTypes(), true)
            ? $validation->maxFileSizeForDisplay($type)
            : '100MB';
        $extensions = in_array($type, $validation->supportedTypes(), true)
            ? $validation->allowedExtensionsForDisplay($type)
            : 'MP3, WAV, M4A, MP4, MOV, AVI, MKV';

        return [
            'file.required' => 'Please select a media file to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => "Invalid file type. Supported formats: {$extensions}.",
            'file.max' => "The file size must not exceed {$maxSize}.",
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
