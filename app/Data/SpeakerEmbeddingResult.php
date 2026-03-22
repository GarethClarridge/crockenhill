<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class SpeakerEmbeddingResult extends Data
{
    /**
     * @param  list<float>|null  $embedding
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?array $embedding = null,
        public readonly ?float $durationUsed = null,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * @param  list<float>  $embedding
     */
    public static function success(array $embedding, float $durationUsed): self
    {
        return new self(
            success: true,
            embedding: $embedding,
            durationUsed: $durationUsed,
        );
    }

    public static function failed(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
        );
    }
}
