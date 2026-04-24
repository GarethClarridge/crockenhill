<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ConfirmMediaSegmentRequest extends MediaProcessingRequest
{
    protected function prepareForValidation(): void
    {
        $this->assertProcessingIdShape();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'segment_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
