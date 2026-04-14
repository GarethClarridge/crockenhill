<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', \Illuminate\Validation\Rule::enum(\App\Enums\SermonService::class)],
            'preacher' => ['nullable', 'string', 'max:255'],
            'preacher_id' => ['nullable', 'integer', 'exists:preachers,id'],
            'series' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'in:date,title,preacher,series,service'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with_thumbnail' => ['nullable', 'boolean'],
        ];
    }
}
