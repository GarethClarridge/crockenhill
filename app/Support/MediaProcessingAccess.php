<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ApiTokenAbility;
use App\Models\User;
use Illuminate\Http\Request;

class MediaProcessingAccess
{
    public function allows(Request $request): bool
    {
        return $this->denialMessage($request) === null;
    }

    public function denialMessage(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->canAccessAdmin()) {
            return 'Unauthorized action.';
        }

        if ($request->bearerToken() !== null && ! $user->tokenCan(ApiTokenAbility::MediaProcess->value)) {
            return 'Missing required token ability: '.ApiTokenAbility::MediaProcess->value;
        }

        return null;
    }
}
