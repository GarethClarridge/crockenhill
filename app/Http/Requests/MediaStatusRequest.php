<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MediaStatusRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('include_logs')) {
            $this->merge([
                'include_logs' => $this->normalizeBoolean($this->input('include_logs')),
            ]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
