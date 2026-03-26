<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Sermon;

$sermon = Sermon::first();
if ($sermon) {
    echo "Sermon found: " . $sermon->title . "\n";
    try {
        echo "Latest Processing Log: " . ($sermon->latestProcessingLog ? $sermon->latestProcessingLog->id : "None") . "\n";
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "No sermons found.\n";
}
