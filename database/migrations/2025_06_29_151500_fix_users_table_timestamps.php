<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE users MODIFY created_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE users MODIFY updated_at TIMESTAMP NULL DEFAULT NULL");
    }

    public function down()
    {
        DB::statement("ALTER TABLE users MODIFY created_at TIMESTAMP NOT NULL");
        DB::statement("ALTER TABLE users MODIFY updated_at TIMESTAMP NOT NULL");
    }
};
