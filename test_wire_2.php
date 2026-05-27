<?php
require 'vendor/autoload.php';
use Illuminate\View\ComponentAttributeBag;

$attributes = new ComponentAttributeBag(['wire:model' => 'title']);
try {
    echo "Exists: " . ($attributes->has('wire:model') ? 'yes' : 'no') . "\n";
    echo "Get: " . $attributes->get('wire:model') . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
