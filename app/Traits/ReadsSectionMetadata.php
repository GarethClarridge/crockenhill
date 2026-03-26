<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\ServiceSection;

trait ReadsSectionMetadata
{
    /**
     * @return array<string, mixed>
     */
    private function metadata(ServiceSection $section): array
    {
        return $section->metadata?->toArray() ?? [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function reviewFlags(array $metadata): array
    {
        $flags = $metadata['review_flags'] ?? [];

        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $flag): ?string => is_string($flag) ? $flag : null, $flags)
        ));
    }

    /**
     * @param  array<int, string>  $reviewFlags
     */
    private function hasBlockingReviewFlag(array $reviewFlags): bool
    {
        return $reviewFlags !== [];
    }
}
