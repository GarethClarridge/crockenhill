<?php
require 'vendor/autoload.php';
use App\Services\Processing\MetadataExtractionService;
use Illuminate\Http\UploadedFile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

// Setup Laravel app for facades
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Log::fake(); // Removed as it fails
Carbon::setTestNow('2026-01-01 10:00:00');

$service = new MetadataExtractionService();

$file = Mockery::mock(UploadedFile::class);
$file->shouldReceive('getClientOriginalName')->andReturn('test.mp3');
$file->shouldReceive('hashName')->andReturn('hash.mp3');
$file->shouldReceive('getClientOriginalExtension')->andReturn('mp3');
$file->shouldReceive('getSize')->andReturn(1024);

// GetId3 constructor calls getPathname() on UploadedFile
$file->shouldReceive('getPathname')->andThrow(new \Exception('Forced fallback'));

try {
    $metadata = $service->extractFromUploadedFile($file);
    echo "Format: " . ($metadata->format ?? 'NULL') . "\n";
} catch (\Exception $e) {
    echo "Caught in script: " . $e->getMessage() . "\n";
}
