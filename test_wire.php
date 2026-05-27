<?php
require 'vendor/autoload.php';
use Illuminate\View\ComponentAttributeBag;

$attributes = new ComponentAttributeBag(['class' => 'foo']);
try {
    echo "Value: " . $attributes->wire('model')->value() . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
