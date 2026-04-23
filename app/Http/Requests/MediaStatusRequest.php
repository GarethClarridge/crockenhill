<?php

declare(strict_types=1);

namespace App\Http\Requests;

class MediaStatusRequest extends MediaProcessingRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'processingId' => $this->route('processingId'),
        ]);

        if ($this->has('include_logs')) {
            $this->merge([
                'include_logs' => $this->normalizeBoolean($this->input('include_logs')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'processingId' => ['required', 'uuid'],
            'include_logs' => ['nullable', 'boolean'],
            'log_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }
}
