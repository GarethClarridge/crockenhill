<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SermonService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SermonIndexRequest extends FormRequest
{
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
     * Security: Strict input validation is enforced on query parameters to provide
     * Defense in Depth against malformed input and potential Denial of Service (DoS)
     * attacks by ensuring all inputs are bounded and correctly typed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255', Rule::enum(SermonService::class)],
            'preacher' => ['nullable', 'string', 'max:255'],
            // Security: integer bounding guards against malformed input and overflow before the exists lookup runs.
            'preacher_id' => ['nullable', 'digits_between:1,10', 'integer', 'min:1', 'max:2147483647', 'exists:preachers,id'],
            'series' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'max:255', 'in:date,title,preacher,series,service'],
            'order' => ['nullable', 'string', 'max:255', 'in:asc,desc'],
            'per_page' => ['nullable', 'digits_between:1,3', 'integer', 'min:1', 'max:100'],
            // Security: input length is bounded to provide Defense in Depth against DoS.
            'with_thumbnail' => ['nullable', 'boolean', 'max:20'],
        ];
    }
}
