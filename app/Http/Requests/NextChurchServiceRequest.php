<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApiTokenAbility;
use App\Enums\SermonService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NextChurchServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->canAccessAdmin()) {
            return false;
        }

        if ($this->bearerToken() !== null && ! $user->tokenCan(ApiTokenAbility::ServiceUpload->value)) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Security: Strict input validation is enforced on query parameters to provide
     * Defense in Depth against malformed input and potential Denial of Service (DoS)
     * attacks by ensuring all inputs are bounded and correctly typed.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Security: input length is bounded to provide Defense in Depth against DoS.
            'service' => ['nullable', 'string', 'max:20', Rule::enum(SermonService::class)],
        ];
    }
}
