<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;

trait WithAdminSave
{
    use SanitizesLogData, WithAdminAuthorization;

    /**
     * Authorize, execute the save callable, write the audit log, and notify.
     *
     * The $save callable returns an array of extra log fields to merge into
     * the audit entry alongside admin_id.
     *
     * @param  callable(): array<string, mixed>  $save
     * @param  array<string, mixed>  $logFields
     */
    protected function adminSave(callable $save, string $logAction, array $logFields = []): void
    {
        $this->authorizeAdmin();

        $extra = $save();

        Log::warning($logAction, $this->sanitizeArrayForLog(array_merge(
            ['admin_id' => auth()->id()],
            $logFields,
            $extra,
        )));
    }
}
