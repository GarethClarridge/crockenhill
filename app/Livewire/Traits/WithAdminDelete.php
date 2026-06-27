<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Traits\SanitizesLogData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait WithAdminDelete
{
    use SanitizesLogData, WithAdminAuthorization;

    /**
     * Authorize, write the audit log, delete the model, and notify.
     *
     * @param  array<string, mixed>  $logFields
     */
    protected function adminDelete(Model $model, string $logAction, array $logFields = []): void
    {
        $this->authorizeAdmin();

        Log::warning($logAction, $this->sanitizeArrayForLog(array_merge(
            ['admin_id' => auth()->id()],
            $logFields,
        )));

        $model->delete();
    }
}
