<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sermon;
use App\Traits\SanitizesLogData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class SermonAdminController extends Controller
{
    use SanitizesLogData;

    /**
     * Remove the specified resource from storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function destroy(Sermon $sermon): RedirectResponse
    {
        $this->authorize('delete', $sermon);

        Log::warning('Sermon deleted by admin', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'sermon_id' => $sermon->id,
            'title' => $sermon->title,
        ]));

        $sermon->delete();

        return redirect()->route('sermons.index')->with('message', 'Sermon successfully deleted!');
    }
}
