<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessingStrategyInterface;
use App\Exceptions\UnsupportedProcessingTypeException;

/**
 * ProcessingStrategyRegistry - Registry for processing strategies
 *
 * Manages strategy instances and provides strategy resolution based on processing type.
 * Part of the strategy pattern implementation as outlined in the refactoring plan.
 */
class ProcessingStrategyRegistry
{
    /**
     * @var array<string, ProcessingStrategyInterface>
     */
    private array $strategies = [];

    /**
     * @param array<string, ProcessingStrategyInterface> $strategies
     */
    public function __construct(array $strategies = [])
    {
        $this->strategies = $strategies;
    }

    /**
     * Register a strategy for a specific type
     */
    public function register(string $type, ProcessingStrategyInterface $strategy): void
    {
        $this->strategies[$type] = $strategy;
    }

    /**
     * Get strategy for a specific processing type
     */
    public function getStrategy(string $type): ProcessingStrategyInterface
    {
        if (!isset($this->strategies[$type])) {
            throw new UnsupportedProcessingTypeException("No strategy registered for type: {$type}");
        }

        return $this->strategies[$type];
    }

    /**
     * Check if strategy exists for type
     */
    public function hasStrategy(string $type): bool
    {
        return isset($this->strategies[$type]);
    }

    /**
     * Get all registered strategy types
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->strategies);
    }

    /**
     * Get configuration for all registered strategies
     */
    public function getAllConfigurations(): array
    {
        $configurations = [];

        foreach ($this->strategies as $type => $strategy) {
            $configurations[$type] = $strategy->getConfiguration();
        }

        return $configurations;
    }
}