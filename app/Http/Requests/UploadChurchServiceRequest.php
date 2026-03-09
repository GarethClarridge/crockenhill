<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApiTokenAbility;
use Illuminate\Foundation\Http\FormRequest;

class UploadChurchServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->is_admin || ! $user->hasVerifiedEmail()) {
            return false;
        }

        if ($this->bearerToken() !== null && ! $user->tokenCan(ApiTokenAbility::SERVICE_UPLOAD->value)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        $maxSize = (int) config('service-tracking.upload.max_size_kb', 614400);

        return [
            'file' => ['required', 'file', 'mimes:zip', 'max:'.$maxSize],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please upload an OpenLP .osz file.',
            'file.file' => 'The uploaded value must be a file.',
            'file.mimes' => 'The uploaded file must be a valid OpenLP .osz archive.',
            'file.max' => 'The uploaded file exceeds the maximum configured size.',
        ];
    }
}
