<?php

declare(strict_types=1);

namespace App\Http\Requests;

class CancelMediaProcessingRequest extends MediaProcessingRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'processingId' => $this->route('processingId'),
        ]);
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
        ];
    }
}
