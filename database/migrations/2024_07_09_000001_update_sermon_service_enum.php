<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Convert any legacy or unexpected values to the correct enum values
        DB::table('sermons')->where('service', 'am')->update(['service' => 'morning']);
        DB::table('sermons')->where('service', 'pm')->update(['service' => 'evening']);
        // Set any other unexpected values to 'other'
        DB::table('sermons')->whereNotIn('service', ['morning', 'evening', 'other'])->update(['service' => 'other']);
    }

    public function down(): void
    {
        // No-op: cannot reliably revert
    }
}; 