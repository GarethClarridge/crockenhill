<?php
require 'vendor/autoload.php';
use App\Services\Processing\MetadataExtractionService;
use Carbon\Carbon;

$service = new MetadataExtractionService();
$metadata = $service->extractFromFilePath('non-existent.mp3');
echo "Format: " . ($metadata->format ?? 'NULL') . "\n";
