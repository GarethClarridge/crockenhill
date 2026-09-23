<?php

declare(strict_types=1);

use App\View\Components\Layout\Header;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The talks archive moved from /christ/sermons to /christ/talks; the nav page
 * whose slug builds that link moves with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pages')
            ->where('area', 'christ')
            ->where('slug', 'sermons')
            ->update(['slug' => 'talks', 'heading' => 'Talks']);

        Cache::forget(Header::NAV_CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('pages')
            ->where('area', 'christ')
            ->where('slug', 'talks')
            ->update(['slug' => 'sermons', 'heading' => 'Sermons']);

        Cache::forget(Header::NAV_CACHE_KEY);
    }
};
