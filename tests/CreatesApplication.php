<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Ensure a clean database state for MySQL before loading the schema
        // This is specific for MySQL. For other databases, adjustments would be needed.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $tables = DB::select('SHOW TABLES');

            // Determine the key for table names in the result set
            // e.g., 'Tables_in_your_database_name'
            $tableKey = null;
            if ($tables) {
                $firstTable = reset($tables); // Get the first table object/array
                if ($firstTable) {
                    $tableKey = key((array)$firstTable); // Get the first key of the first table
                }
            }

            if ($tableKey) {
                foreach ($tables as $table) {
                    $tableName = $table->$tableKey;
                    DB::table($tableName)->truncate();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        $schemaPath = __DIR__.'/../database/schema/crockenhill-schema.sql';
        // Read the SQL file content and execute it
        // DB::unprepared is suitable for executing schema files that might contain multiple statements.
        DB::unprepared(file_get_contents($schemaPath));

        return $app;
    }
}
