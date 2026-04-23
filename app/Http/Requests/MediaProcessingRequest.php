<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApiTokenAbility;
use Illuminate\Foundation\Http\FormRequest;

abstract class MediaProcessingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Provides Defense in Depth alongside the media.process middleware.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user?->canAccessAdmin() !== true) {
            return false;
        }

        // When using a bearer token (e.g., from a separate uploader tool),
        // we must also verify the granular token ability.
        if ($this->bearerToken() !== null && ! $user->tokenCan(ApiTokenAbility::MEDIA_PROCESS->value)) {
            return false;
        }

        return true;
    }
}
