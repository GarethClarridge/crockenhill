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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Security: string and max:255 are enforced before the enum rule to ensure
            // the input is bounded and correctly typed early in the validation lifecycle.
            'service' => ['nullable', 'string', 'max:255', Rule::enum(SermonService::class)],
        ];
    }
}
