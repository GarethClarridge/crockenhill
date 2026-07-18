<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ConfirmMediaSegmentRequest extends MediaProcessingRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Security: digits_between validates digit length early to protect against DoS and overflow.
            'segment_id' => ['required', 'integer', 'min:1', 'digits_between:1,10', 'max:2147483647', 'exists:livestream_segments,id'],
        ];
    }
}
