<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class MediaStatusRequest extends MediaProcessingRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('include_logs')) {
            $this->merge([
                'include_logs' => $this->normalizeBoolean($this->input('include_logs')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Security: input length is bounded to provide Defense in Depth against DoS.
            'include_logs' => ['nullable', 'boolean', 'max:20'],
            'log_limit' => ['nullable', 'integer', 'digits_between:1,3', 'min:1', 'max:100'],
        ];
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Security: length check before normalization to prevent DoS on string operations.
        if (mb_strlen($value) > 20) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }
}
