<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Store current sql_mode
        $currentMode = DB::select("SELECT @@sql_mode as mode")[0]->mode;
        
        // Temporarily disable strict mode
        DB::statement("SET sql_mode = ''");
        
        try {
            // Update invalid timestamp values to NULL
            DB::statement("UPDATE users SET created_at = NULL WHERE created_at = '0000-00-00 00:00:00' OR created_at < '1970-01-01'");
            DB::statement("UPDATE users SET updated_at = NULL WHERE updated_at = '0000-00-00 00:00:00' OR updated_at < '1970-01-01'");
            
            // Modify the columns
            DB::statement("ALTER TABLE users MODIFY created_at TIMESTAMP NULL DEFAULT NULL");
            DB::statement("ALTER TABLE users MODIFY updated_at TIMESTAMP NULL DEFAULT NULL");
        } finally {
            // Restore original sql_mode
            DB::statement("SET sql_mode = ?", [$currentMode]);
        }
    }

    public function down()
    {
        DB::statement("ALTER TABLE users MODIFY created_at TIMESTAMP NOT NULL");
        DB::statement("ALTER TABLE users MODIFY updated_at TIMESTAMP NOT NULL");
    }
};
